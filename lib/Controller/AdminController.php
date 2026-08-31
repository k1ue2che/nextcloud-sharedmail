<?php

declare(strict_types=1);

namespace OCA\SharedMail\Controller;

use OCA\SharedMail\AppInfo\Application;
use OCA\SharedMail\Db\AccessRule;
use OCA\SharedMail\Db\AccessRuleMapper;
use OCA\SharedMail\Db\Mailbox;
use OCA\SharedMail\Db\MailboxMapper;
use OCA\SharedMail\Service\CredentialService;
use OCA\SharedMail\Service\MailConnectionTestService;
use OCA\SharedMail\Service\MailboxPermission;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use Throwable;

class AdminController extends Controller
{
    private const ALLOWED_SECURITY = [
        'ssl',
        'tls',
        'none',
    ];

    public function __construct(
        IRequest $request,
        private readonly MailboxMapper $mailboxMapper,
        private readonly AccessRuleMapper $accessRuleMapper,
        private readonly CredentialService $credentialService,
        private readonly IGroupManager $groupManager,
        private readonly MailConnectionTestService $connectionTestService,
        private readonly IDBConnection $db,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    public function createMailbox(
        string $name,
        string $email,
        string $imapHost,
        int $imapPort,
        string $imapSecurity,
        string $imapUsername,
        string $imapPassword,
        string $smtpHost,
        int $smtpPort,
        string $smtpSecurity,
        string $smtpUsername,
        string $smtpPassword,
        string $description = '',
        array $groupIds = [],
    ): JSONResponse {
        $name = trim($name);
        $email = trim($email);
        $description = trim($description);

        $imapHost = trim($imapHost);
        $imapUsername = trim($imapUsername);
        $imapSecurity = strtolower(trim($imapSecurity));

        $smtpHost = trim($smtpHost);
        $smtpUsername = trim($smtpUsername);
        $smtpSecurity = strtolower(trim($smtpSecurity));

        /*
         * Grunddaten prüfen
         */
        if ($name === '') {
            return $this->error(
                'Name darf nicht leer sein.',
                400
            );
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error(
                'Ungültige E-Mail-Adresse.',
                400
            );
        }

        if ($imapHost === '' || $smtpHost === '') {
            return $this->error(
                'IMAP- und SMTP-Host sind erforderlich.',
                400
            );
        }

        if (!$this->isValidPort($imapPort)
            || !$this->isValidPort($smtpPort)) {
            return $this->error(
                'Ungültiger IMAP- oder SMTP-Port.',
                400
            );
        }

        if (!$this->isValidSecurity($imapSecurity)
            || !$this->isValidSecurity($smtpSecurity)) {
            return $this->error(
                'Ungültige Verschlüsselungsart.',
                400
            );
        }

        /*
         * Zugriffsgruppen normalisieren
         */
        $groupIds = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn ($groupId): string => trim((string)$groupId),
                        $groupIds
                    ),
                    static fn (string $groupId): bool => $groupId !== ''
                )
            )
        );

        if ($groupIds === []) {
            return $this->error(
                'Mindestens eine Zugriffsgruppe muss ausgewählt werden.',
                400
            );
        }

        /*
         * Prüfen, ob die Gruppen in Nextcloud existieren
         */
        foreach ($groupIds as $groupId) {
            if (!$this->groupManager->groupExists($groupId)) {
                return $this->error(
                    'Die Gruppe "' . $groupId . '" existiert nicht.',
                    400
                );
            }
        }

        /*
         * Mailbox-Entity vorbereiten
         */
        $now = time();

        $mailbox = new Mailbox();

        $mailbox->setName($name);
        $mailbox->setDescription(
            $description !== ''
                ? $description
                : null
        );

        $mailbox->setEmail($email);

        $mailbox->setImapHost($imapHost);
        $mailbox->setImapPort($imapPort);
        $mailbox->setImapSecurity($imapSecurity);
        $mailbox->setImapUsername($imapUsername);
        $mailbox->setImapPassword(
            $this->credentialService->encrypt($imapPassword)
        );

        $mailbox->setSmtpHost($smtpHost);
        $mailbox->setSmtpPort($smtpPort);
        $mailbox->setSmtpSecurity($smtpSecurity);
        $mailbox->setSmtpUsername($smtpUsername);
        $mailbox->setSmtpPassword(
            $this->credentialService->encrypt($smtpPassword)
        );

        $mailbox->setEnabled(true);
        $mailbox->setCreatedAt($now);
        $mailbox->setUpdatedAt($now);

        /*
         * Mailbox und Gruppen gemeinsam speichern.
         *
         * Entweder alles wird gespeichert oder gar nichts.
         */
        $transactionStarted = false;

        try {
            $this->db->beginTransaction();
            $transactionStarted = true;

            $mailbox = $this->mailboxMapper->insert($mailbox);

            foreach ($groupIds as $groupId) {
                $accessRule = new AccessRule();

                $accessRule->setMailboxId(
                    (int)$mailbox->getId()
                );
                $accessRule->setPrincipalType('group');
                $accessRule->setPrincipalId($groupId);
                $accessRule->setPermissions(
                    MailboxPermission::DEFAULT
                );
                $accessRule->setCreatedAt($now);

                $this->accessRuleMapper->insert($accessRule);
            }

            $this->db->commit();
            $transactionStarted = false;
        } catch (Throwable) {
            if ($transactionStarted) {
                $this->rollbackQuietly();
            }

            return $this->error(
                'Postfach konnte nicht gespeichert werden.',
                500
            );
        }

        return new JSONResponse([
            'success' => true,
            'mailbox' => [
                'id' => $mailbox->getId(),
                'name' => $mailbox->getName(),
                'email' => $mailbox->getEmail(),
                'enabled' => $mailbox->getEnabled(),
            ],
        ]);
    }

    public function testConnection(
        string $imapHost,
        int $imapPort,
        string $imapSecurity,
        string $imapUsername,
        string $imapPassword,
        string $smtpHost,
        int $smtpPort,
        string $smtpSecurity,
        string $smtpUsername,
        string $smtpPassword,
    ): JSONResponse {
        $imapHost = trim($imapHost);
        $imapUsername = trim($imapUsername);
        $imapSecurity = strtolower(trim($imapSecurity));

        $smtpHost = trim($smtpHost);
        $smtpUsername = trim($smtpUsername);
        $smtpSecurity = strtolower(trim($smtpSecurity));

        if ($imapHost === '' || $smtpHost === '') {
            return $this->error(
                'IMAP- und SMTP-Host müssen angegeben werden.',
                400
            );
        }

        if (!$this->isValidPort($imapPort)
            || !$this->isValidPort($smtpPort)) {
            return $this->error(
                'Ungültiger IMAP- oder SMTP-Port.',
                400
            );
        }

        if (!$this->isValidSecurity($imapSecurity)
            || !$this->isValidSecurity($smtpSecurity)) {
            return $this->error(
                'Ungültige Verschlüsselungsart.',
                400
            );
        }

        $imap = $this->connectionTestService->testImap(
            $imapHost,
            $imapPort,
            $imapSecurity,
            $imapUsername,
            $imapPassword,
        );

        $smtp = $this->connectionTestService->testSmtp(
            $smtpHost,
            $smtpPort,
            $smtpSecurity,
            $smtpUsername,
            $smtpPassword,
        );

        return new JSONResponse([
            'success' => (
                $imap['success']
                && $smtp['success']
            ),
            'imap' => $imap,
            'smtp' => $smtp,
        ]);
    }

    public function deleteMailbox(
        int $id,
    ): JSONResponse {
        /*
         * Erst prüfen, ob das Postfach überhaupt existiert.
         */
        try {
            $mailbox = $this->mailboxMapper->find($id);
        } catch (Throwable) {
            return $this->error(
                'Postfach wurde nicht gefunden.',
                404
            );
        }

        /*
         * AccessRules und Mailbox gemeinsam entfernen.
         *
         * Das echte IMAP-/SMTP-Konto und dessen Nachrichten
         * werden dadurch NICHT verändert.
         */
        $transactionStarted = false;

        try {
            $this->db->beginTransaction();
            $transactionStarted = true;

            $this->accessRuleMapper->deleteByMailbox($id);
            $this->mailboxMapper->delete($mailbox);

            $this->db->commit();
            $transactionStarted = false;
        } catch (Throwable) {
            if ($transactionStarted) {
                $this->rollbackQuietly();
            }

            return $this->error(
                'Postfach konnte nicht gelöscht werden.',
                500
            );
        }

        return new JSONResponse([
            'success' => true,
        ]);
    }

    private function isValidPort(
        int $port,
    ): bool {
        return $port >= 1
            && $port <= 65535;
    }

    private function isValidSecurity(
        string $security,
    ): bool {
        return in_array(
            $security,
            self::ALLOWED_SECURITY,
            true
        );
    }

    private function error(
        string $message,
        int $status,
    ): JSONResponse {
        return new JSONResponse([
            'success' => false,
            'error' => $message,
        ], $status);
    }

    private function rollbackQuietly(): void
    {
        try {
            $this->db->rollBack();
        } catch (Throwable) {
            /*
             * Der ursprüngliche Datenbankfehler ist wichtiger.
             * Ein zusätzlicher Rollback-Fehler soll ihn nicht
             * überschreiben.
             */
        }
    }
}