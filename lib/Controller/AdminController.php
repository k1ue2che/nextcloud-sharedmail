<?php

declare(strict_types=1);

namespace OCA\SharedMail\Controller;

use InvalidArgumentException;
use OCA\SharedMail\AppInfo\Application;
use OCA\SharedMail\Db\AccessRule;
use OCA\SharedMail\Db\AccessRuleMapper;
use OCA\SharedMail\Db\Mailbox;
use OCA\SharedMail\Db\MailboxMapper;
use OCA\SharedMail\Service\CredentialService;
use OCA\SharedMail\Service\MailConnectionTestService;
use OCA\SharedMail\Service\MailboxPermission;
use OCA\SharedMail\Service\MessageStateService;
use OCP\IL10N;
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
        private readonly MessageStateService $messageStateService,
        private readonly IDBConnection $db,
        private readonly IL10N $l,
    ) {
        parent::__construct(
            Application::APP_ID,
            $request
        );
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
        array $groupPermissions = [],
    ): JSONResponse {
        $name =
            trim($name);

        $email =
            trim($email);

        $description =
            trim($description);

        $imapHost =
            trim($imapHost);

        $imapUsername =
            trim($imapUsername);

        $imapSecurity =
            strtolower(
                trim($imapSecurity)
            );

        $smtpHost =
            trim($smtpHost);

        $smtpUsername =
            trim($smtpUsername);

        $smtpSecurity =
            strtolower(
                trim($smtpSecurity)
            );

        /*
         * Grunddaten prüfen.
         */
        if ($name === '') {
            return $this->error(
                $this->l->t('Name must not be empty.'),
                400
            );
        }

        if (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            return $this->error(
                $this->l->t('Invalid email address.'),
                400
            );
        }

        if (
            $imapHost === ''
            || $smtpHost === ''
        ) {
            return $this->error(
                $this->l->t('IMAP and SMTP hosts are required.'),
                400
            );
        }

        if (
            !$this->isValidPort(
                $imapPort
            )
            || !$this->isValidPort(
                $smtpPort
            )
        ) {
            return $this->error(
                $this->l->t('Invalid IMAP or SMTP port.'),
                400
            );
        }

        if (
            !$this->isValidSecurity(
                $imapSecurity
            )
            || !$this->isValidSecurity(
                $smtpSecurity
            )
        ) {
            return $this->error(
                $this->l->t('Invalid encryption type.'),
                400
            );
        }

        /*
         * Zugriffsgruppen normalisieren.
         */
        $groupIds =
            $this->normalizeGroupIds(
                $groupIds
            );

        if ($groupIds === []) {
            return $this->error(
                $this->l->t('At least one access group must be selected.'),
                400
            );
        }

        /*
         * Prüfen, ob die Gruppen in Nextcloud
         * tatsächlich existieren.
         */
        foreach ($groupIds as $groupId) {
            if (
                !$this
                    ->groupManager
                    ->groupExists(
                        $groupId
                    )
            ) {
                return $this->error(
                    $this->l->t(
                        'The group "%s" does not exist.',
                        [$groupId]
                    ),
                    400
                );
            }
        }

        /*
         * Gruppenrechte prüfen und normalisieren.
         *
         * Ältere Clients schicken groupPermissions
         * noch nicht mit. In diesem Fall wird
         * MailboxPermission::DEFAULT verwendet.
         */
        try {
            $permissionsByGroup =
                $this->normalizeGroupPermissions(
                    $groupIds,
                    $groupPermissions
                );
        } catch (
            InvalidArgumentException $e
        ) {
            return $this->error(
                $e->getMessage(),
                400
            );
        }

        /*
         * Mailbox-Entity vorbereiten.
         */
        $now =
            time();

        $mailbox =
            new Mailbox();

        $mailbox->setName(
            $name
        );

        $mailbox->setDescription(
            $description !== ''
                ? $description
                : null
        );

        $mailbox->setEmail(
            $email
        );

        $mailbox->setImapHost(
            $imapHost
        );

        $mailbox->setImapPort(
            $imapPort
        );

        $mailbox->setImapSecurity(
            $imapSecurity
        );

        $mailbox->setImapUsername(
            $imapUsername
        );

        $mailbox->setImapPassword(
            $this
                ->credentialService
                ->encrypt(
                    $imapPassword
                )
        );

        $mailbox->setSmtpHost(
            $smtpHost
        );

        $mailbox->setSmtpPort(
            $smtpPort
        );

        $mailbox->setSmtpSecurity(
            $smtpSecurity
        );

        $mailbox->setSmtpUsername(
            $smtpUsername
        );

        $mailbox->setSmtpPassword(
            $this
                ->credentialService
                ->encrypt(
                    $smtpPassword
                )
        );

        $mailbox->setEnabled(
            true
        );

        $mailbox->setCreatedAt(
            $now
        );

        $mailbox->setUpdatedAt(
            $now
        );

        /*
         * Mailbox und Gruppenrechte gemeinsam
         * speichern.
         *
         * Entweder alles wird gespeichert oder
         * gar nichts.
         */
        $transactionStarted =
            false;

        try {
            $this->db
                ->beginTransaction();

            $transactionStarted =
                true;

            $mailbox =
                $this
                    ->mailboxMapper
                    ->insert(
                        $mailbox
                    );

            foreach (
                $groupIds
                as $groupId
            ) {
                $accessRule =
                    new AccessRule();

                $accessRule->setMailboxId(
                    (int)$mailbox->getId()
                );

                $accessRule->setPrincipalType(
                    'group'
                );

                $accessRule->setPrincipalId(
                    $groupId
                );

                $accessRule->setPermissions(
                    $permissionsByGroup[
                        $groupId
                    ]
                );

                $accessRule->setCreatedAt(
                    $now
                );

                $this
                    ->accessRuleMapper
                    ->insert(
                        $accessRule
                    );
            }

            $this->db
                ->commit();

            $transactionStarted =
                false;
        } catch (Throwable) {
            if ($transactionStarted) {
                $this->rollbackQuietly();
            }

            return $this->error(
                $this->l->t('The mailbox could not be saved.'),
                500
            );
        }

        return new JSONResponse([
            'success' =>
                true,

            'mailbox' => [
                'id' =>
                    $mailbox->getId(),

                'name' =>
                    $mailbox->getName(),

                'email' =>
                    $mailbox->getEmail(),

                'enabled' =>
                    $mailbox->getEnabled(),
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
        $imapHost =
            trim($imapHost);

        $imapUsername =
            trim($imapUsername);

        $imapSecurity =
            strtolower(
                trim($imapSecurity)
            );

        $smtpHost =
            trim($smtpHost);

        $smtpUsername =
            trim($smtpUsername);

        $smtpSecurity =
            strtolower(
                trim($smtpSecurity)
            );

        if (
            $imapHost === ''
            || $smtpHost === ''
        ) {
            return $this->error(
                $this->l->t('IMAP and SMTP hosts must be specified.'),
                400
            );
        }

        if (
            !$this->isValidPort(
                $imapPort
            )
            || !$this->isValidPort(
                $smtpPort
            )
        ) {
            return $this->error(
                $this->l->t('Invalid IMAP or SMTP port.'),
                400
            );
        }

        if (
            !$this->isValidSecurity(
                $imapSecurity
            )
            || !$this->isValidSecurity(
                $smtpSecurity
            )
        ) {
            return $this->error(
                $this->l->t('Invalid encryption type.'),
                400
            );
        }

        $imap =
            $this
                ->connectionTestService
                ->testImap(
                    $imapHost,
                    $imapPort,
                    $imapSecurity,
                    $imapUsername,
                    $imapPassword,
                );

        $smtp =
            $this
                ->connectionTestService
                ->testSmtp(
                    $smtpHost,
                    $smtpPort,
                    $smtpSecurity,
                    $smtpUsername,
                    $smtpPassword,
                );

        return new JSONResponse([
            'success' =>
                (
                    $imap['success']
                    && $smtp['success']
                ),

            'imap' =>
                $imap,

            'smtp' =>
                $smtp,
        ]);
    }

    public function updateMailbox(
        int $id,
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
        array $groupPermissions = [],
    ): JSONResponse {
        $name =
            trim($name);

        $email =
            trim($email);

        $description =
            trim($description);

        $imapHost =
            trim($imapHost);

        $imapUsername =
            trim($imapUsername);

        $imapSecurity =
            strtolower(
                trim($imapSecurity)
            );

        $smtpHost =
            trim($smtpHost);

        $smtpUsername =
            trim($smtpUsername);

        $smtpSecurity =
            strtolower(
                trim($smtpSecurity)
            );

        if ($name === '') {
            return $this->error(
                $this->l->t('Name must not be empty.'),
                400
            );
        }

        if (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            return $this->error(
                $this->l->t('Invalid email address.'),
                400
            );
        }

        if (
            $imapHost === ''
            || $smtpHost === ''
        ) {
            return $this->error(
                $this->l->t('IMAP and SMTP hosts are required.'),
                400
            );
        }

        if (
            !$this->isValidPort(
                $imapPort
            )
            || !$this->isValidPort(
                $smtpPort
            )
        ) {
            return $this->error(
                $this->l->t('Invalid IMAP or SMTP port.'),
                400
            );
        }

        if (
            !$this->isValidSecurity(
                $imapSecurity
            )
            || !$this->isValidSecurity(
                $smtpSecurity
            )
        ) {
            return $this->error(
                $this->l->t('Invalid encryption type.'),
                400
            );
        }

        $groupIds =
            $this->normalizeGroupIds(
                $groupIds
            );

        if ($groupIds === []) {
            return $this->error(
                $this->l->t('At least one access group must be selected.'),
                400
            );
        }

        foreach ($groupIds as $groupId) {
            if (
                !$this
                    ->groupManager
                    ->groupExists(
                        $groupId
                    )
            ) {
                return $this->error(
                    $this->l->t(
                        'The group "%s" does not exist.',
                        [$groupId]
                    ),
                    400
                );
            }
        }

        try {
            $permissionsByGroup =
                $this->normalizeGroupPermissions(
                    $groupIds,
                    $groupPermissions
                );
        } catch (
            InvalidArgumentException $e
        ) {
            return $this->error(
                $e->getMessage(),
                400
            );
        }

        try {
            $mailbox =
                $this
                    ->mailboxMapper
                    ->find(
                        $id
                    );
        } catch (Throwable) {
            return $this->error(
                $this->l->t('Mailbox was not found.'),
                404
            );
        }

        $mailbox->setName(
            $name
        );

        $mailbox->setDescription(
            $description !== ''
                ? $description
                : null
        );

        $mailbox->setEmail(
            $email
        );

        $mailbox->setImapHost(
            $imapHost
        );

        $mailbox->setImapPort(
            $imapPort
        );

        $mailbox->setImapSecurity(
            $imapSecurity
        );

        $mailbox->setImapUsername(
            $imapUsername
        );

        /*
         * Leeres Passwortfeld:
         * vorhandenes Passwort behalten.
         */
        if ($imapPassword !== '') {
            $mailbox->setImapPassword(
                $this
                    ->credentialService
                    ->encrypt(
                        $imapPassword
                    )
            );
        }

        $mailbox->setSmtpHost(
            $smtpHost
        );

        $mailbox->setSmtpPort(
            $smtpPort
        );

        $mailbox->setSmtpSecurity(
            $smtpSecurity
        );

        $mailbox->setSmtpUsername(
            $smtpUsername
        );

        if ($smtpPassword !== '') {
            $mailbox->setSmtpPassword(
                $this
                    ->credentialService
                    ->encrypt(
                        $smtpPassword
                    )
            );
        }

        $mailbox->setUpdatedAt(
            time()
        );

        $transactionStarted =
            false;

        try {
            $this->db
                ->beginTransaction();

            $transactionStarted =
                true;

            /*
             * Mailbox aktualisieren.
             */
            $mailbox =
                $this
                    ->mailboxMapper
                    ->update(
                        $mailbox
                    );

            /*
             * Alte Gruppenrechte entfernen.
             */
            $this
                ->accessRuleMapper
                ->deleteByMailbox(
                    $id
                );

            /*
             * Aktuelle Gruppenrechte neu anlegen.
             */
            $now =
                time();

            foreach (
                $groupIds
                as $groupId
            ) {
                $accessRule =
                    new AccessRule();

                $accessRule->setMailboxId(
                    $id
                );

                $accessRule->setPrincipalType(
                    'group'
                );

                $accessRule->setPrincipalId(
                    $groupId
                );

                $accessRule->setPermissions(
                    $permissionsByGroup[
                        $groupId
                    ]
                );

                $accessRule->setCreatedAt(
                    $now
                );

                $this
                    ->accessRuleMapper
                    ->insert(
                        $accessRule
                    );
            }

            $this->db
                ->commit();

            $transactionStarted =
                false;
        } catch (Throwable) {
            if ($transactionStarted) {
                $this->rollbackQuietly();
            }

            return $this->error(
                $this->l->t('The mailbox could not be updated.'),
                500
            );
        }

        return new JSONResponse([
            'success' =>
                true,

            'mailbox' => [
                'id' =>
                    $mailbox->getId(),

                'name' =>
                    $mailbox->getName(),

                'email' =>
                    $mailbox->getEmail(),

                'enabled' =>
                    $mailbox->getEnabled(),
            ],
        ]);
    }

    public function deleteMailbox(
        int $id,
    ): JSONResponse {
        /*
         * Erst prüfen, ob das Postfach überhaupt
         * existiert.
         */
        try {
            $mailbox =
                $this
                    ->mailboxMapper
                    ->find(
                        $id
                    );
        } catch (Throwable) {
            return $this->error(
                $this->l->t('Mailbox was not found.'),
                404
            );
        }

        /*
         * AccessRules und Mailbox gemeinsam entfernen.
         *
         * Das echte IMAP-/SMTP-Konto und dessen
         * Nachrichten werden dadurch NICHT verändert.
         */
        $transactionStarted =
            false;

        try {
            $this->db
                ->beginTransaction();

            $transactionStarted =
                true;

            $this
                ->messageStateService
                ->deleteByMailbox(
                    $id
                );

            $this
                ->accessRuleMapper
                ->deleteByMailbox(
                    $id
                );

            $this
                ->mailboxMapper
                ->delete(
                    $mailbox
                );

            $this->db
                ->commit();

            $transactionStarted =
                false;
        } catch (Throwable) {
            if ($transactionStarted) {
                $this->rollbackQuietly();
            }

            return $this->error(
                $this->l->t('The mailbox could not be deleted.'),
                500
            );
        }

        return new JSONResponse([
            'success' =>
                true,
        ]);
    }

    /**
     * @param mixed[] $groupIds
     * @return string[]
     */
    private function normalizeGroupIds(
        array $groupIds,
    ): array {
        return array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn ($groupId): string =>
                            trim(
                                (string)$groupId
                            ),
                        $groupIds
                    ),
                    static fn (
                        string $groupId,
                    ): bool =>
                        $groupId !== ''
                )
            )
        );
    }

    /**
     * @param string[] $groupIds
     * @param mixed[] $groupPermissions
     * @return array<string, int>
     */
    private function normalizeGroupPermissions(
        array $groupIds,
        array $groupPermissions,
    ): array {
        $normalized = [];

        foreach ($groupIds as $groupId) {
            /*
             * Abwärtskompatibilität:
             *
             * Falls ein älterer Client noch keine
             * Rechte mitsendet, gelten die
             * Standardrechte.
             */
            $rawPermissions =
                $groupPermissions[
                    $groupId
                ]
                ?? MailboxPermission::DEFAULT;

            if (is_int($rawPermissions)) {
                $permissions =
                    $rawPermissions;
            } elseif (
                is_string($rawPermissions)
                && preg_match(
                    '/^\d+$/',
                    trim($rawPermissions)
                ) === 1
            ) {
                $permissions =
                    (int)trim(
                        $rawPermissions
                    );
            } else {
                throw new InvalidArgumentException(
                    $this->l->t(
                        'Invalid permissions for group "%s".',
                        [$groupId]
                    )
                );
            }

            /*
             * Aktuell existieren genau die Bits
             * aus MailboxPermission::FULL.
             */
            if (
                $permissions < 0
                || $permissions
                    > MailboxPermission::FULL
            ) {
                throw new InvalidArgumentException(
                    $this->l->t(
                        'Invalid permissions for group "%s".',
                        [$groupId]
                    )
                );
            }

            /*
             * Eine ausgewählte Zugriffsgruppe muss
             * das Postfach mindestens lesen können.
             *
             * READ kann deshalb administrativ nicht
             * entfernt werden.
             */
            $permissions |=
                MailboxPermission::READ;

            $normalized[
                $groupId
            ] =
                $permissions;
        }

        return $normalized;
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
        return new JSONResponse(
            [
                'success' =>
                    false,

                'error' =>
                    $message,
            ],
            $status
        );
    }

    private function rollbackQuietly(): void
    {
        try {
            $this->db
                ->rollBack();
        } catch (Throwable) {
            /*
             * Der ursprüngliche Datenbankfehler ist
             * wichtiger.
             *
             * Ein zusätzlicher Rollback-Fehler soll
             * ihn nicht überschreiben.
             */
        }
    }
}