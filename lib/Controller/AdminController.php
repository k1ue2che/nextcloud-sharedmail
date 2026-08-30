<?php

declare(strict_types=1);

namespace OCA\SharedMail\Controller;

use OCA\SharedMail\AppInfo\Application;
use OCA\SharedMail\Db\Mailbox;
use OCA\SharedMail\Db\MailboxMapper;
use OCA\SharedMail\Service\CredentialService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class AdminController extends Controller
{
    public function __construct(
        IRequest $request,
        private readonly MailboxMapper $mailboxMapper,
        private readonly CredentialService $credentialService,
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