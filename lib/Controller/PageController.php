<?php

declare(strict_types=1);

namespace OCA\SharedMail\Controller;

use OCA\SharedMail\AppInfo\Application;
use OCA\SharedMail\Service\MailboxAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class PageController extends Controller
{
    public function __construct(
        IRequest $request,
        private readonly MailboxAccessService $mailboxAccessService,
    ) {
        parent::__construct(
            Application::APP_ID,
            $request
        );
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function index(): TemplateResponse
    {
        $accessibleMailboxes =
            $this->mailboxAccessService
                ->getAccessibleMailboxes();

        /*
         * Nur Daten an den Browser geben,
         * die der Benutzer tatsächlich benötigt.
         *
         * Keine IMAP-/SMTP-Zugangsdaten!
         */
        $mailboxes = array_map(
            static fn ($mailbox): array => [
                'id' => (int)$mailbox->getId(),
                'name' => $mailbox->getName(),
                'description' => $mailbox->getDescription(),
                'email' => $mailbox->getEmail(),
            ],
            $accessibleMailboxes
        );

        return new TemplateResponse(
            Application::APP_ID,
            'main',
            [
                'mailboxes' => $mailboxes,
            ]
        );
    }
}