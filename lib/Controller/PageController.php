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
            $this
                ->mailboxAccessService
                ->getAccessibleMailboxes();

        /*
         * Nur Daten an den Browser geben,
         * die der Benutzer tatsächlich benötigt.
         *
         * Keine IMAP-/SMTP-Zugangsdaten!
         *
         * Zusätzlich werden die effektiven Rechte
         * des aktuell angemeldeten Benutzers für
         * jede Mailbox ausgeliefert.
         */
        $mailboxes =
            array_map(
                function ($mailbox): array {
                    $mailboxId =
                        (int)$mailbox->getId();

                    return [
                        'id' =>
                            $mailboxId,

                        'name' =>
                            $mailbox->getName(),

                        'description' =>
                            $mailbox->getDescription(),

                        'email' =>
                            $mailbox->getEmail(),

                        'permissions' =>
                            $this
                                ->mailboxAccessService
                                ->getPermissions(
                                    $mailboxId
                                ),
                    ];
                },
                $accessibleMailboxes
            );

        return new TemplateResponse(
            Application::APP_ID,
            'main',
            [
                'mailboxes' =>
                    $mailboxes,
            ]
        );
    }
}