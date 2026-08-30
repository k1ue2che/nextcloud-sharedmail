<?php

declare(strict_types=1);

namespace OCA\SharedMail\Controller;

use OCA\SharedMail\AppInfo\Application;
use OCA\SharedMail\Db\Mailbox;
use OCA\SharedMail\Db\MailboxMapper;
use OCA\SharedMail\Db\AccessRule;
use OCA\SharedMail\Db\AccessRuleMapper;
use OCA\SharedMail\Service\CredentialService;
use OCA\SharedMail\Service\MailboxPermission;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IGroupManager;



class AdminController extends Controller
{
    public function __construct(
        IRequest $request,
        private readonly MailboxMapper $mailboxMapper,
        private readonly AccessRuleMapper $accessRuleMapper,
        private readonly CredentialService $credentialService,
        private readonly IGroupManager $groupManager,
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

        if ($name === '') {
            return new JSONResponse(
                ['error' => 'Name darf nicht leer sein.'],
                400
            );
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JSONResponse(
                ['error' => 'Ungültige E-Mail-Adresse.'],
                400
            );
        }

        if ($imapHost === '' || $smtpHost === '') {
            return new JSONResponse(
                ['error' => 'IMAP- und SMTP-Host sind erforderlich.'],
                400
            );
        }

        if ($imapPort < 1 || $imapPort > 65535
            || $smtpPort < 1 || $smtpPort > 65535) {
            return new JSONResponse(
                ['error' => 'Ungültiger Port.'],
                400
            );
        }

        $allowedSecurity = [
            'ssl',
            'tls',
            'none',
        ];

        if (!in_array($imapSecurity, $allowedSecurity, true)
            || !in_array($smtpSecurity, $allowedSecurity, true)) {
            return new JSONResponse(
                ['error' => 'Ungültige Verschlüsselungsart.'],
                400
            );
        }
        
        $groupIds = array_values(
            array_unique(
                array_filter(
                    array_map('strval', $groupIds)
                )
            )
        );

        if ($groupIds === []) {
            return new JSONResponse(
                ['error' => 'Mindestens eine Zugriffsgruppe muss ausgewählt werden.'],
                400
            );
        }

        foreach ($groupIds as $groupId) {
            if (!$this->groupManager->groupExists($groupId)) {
                return new JSONResponse(
                    ['error' => 'Die Gruppe "' . $groupId . '" existiert nicht.'],
                    400
                );
            }
        }

        $now = time();

        $mailbox = new Mailbox();

        $mailbox->setName($name);
        $mailbox->setDescription(
            trim($description) !== ''
                ? trim($description)
                : null
        );

        $mailbox->setEmail($email);

        $mailbox->setImapHost(trim($imapHost));
        $mailbox->setImapPort($imapPort);
        $mailbox->setImapSecurity($imapSecurity);
        $mailbox->setImapUsername(trim($imapUsername));
        $mailbox->setImapPassword(
            $this->credentialService->encrypt($imapPassword)
        );

        $mailbox->setSmtpHost(trim($smtpHost));
        $mailbox->setSmtpPort($smtpPort);
        $mailbox->setSmtpSecurity($smtpSecurity);
        $mailbox->setSmtpUsername(trim($smtpUsername));
        $mailbox->setSmtpPassword(
            $this->credentialService->encrypt($smtpPassword)
        );

        $mailbox->setEnabled(true);

        $mailbox->setCreatedAt($now);
        $mailbox->setUpdatedAt($now);

        $mailbox = $this->mailboxMapper->insert($mailbox);

        foreach ($groupIds as $groupId) {
            $accessRule = new AccessRule();

            $accessRule->setMailboxId((int)$mailbox->getId());
            $accessRule->setPrincipalType('group');
            $accessRule->setPrincipalId($groupId);
            $accessRule->setPermissions(MailboxPermission::DEFAULT);
            $accessRule->setCreatedAt($now);

            $this->accessRuleMapper->insert($accessRule);
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
}