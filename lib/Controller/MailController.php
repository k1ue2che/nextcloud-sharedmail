<?php

declare(strict_types=1);

namespace OCA\SharedMail\Controller;

use OCA\SharedMail\AppInfo\Application;
use OCA\SharedMail\Service\MailboxAccessService;
use OCA\SharedMail\Service\MailboxImapService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

class MailController extends Controller
{
    public function __construct(
        IRequest $request,
        private readonly MailboxAccessService $mailboxAccessService,
        private readonly MailboxImapService $mailboxImapService,
    ) {
        parent::__construct(
            Application::APP_ID,
            $request
        );
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function folders(
        int $id,
    ): JSONResponse {
        /*
         * Ganz wichtig:
         * Nicht einfach mailboxMapper->find($id)!
         *
         * Erst unsere zentrale Zugriffsprüfung.
         */
        $mailbox =
            $this->mailboxAccessService
                ->getAccessibleMailbox($id);

        if ($mailbox === null) {
            return new JSONResponse([
                'success' => false,
                'error' => 'Kein Zugriff auf dieses Postfach.',
            ], 403);
        }

        try {
            $folders =
                $this->mailboxImapService
                    ->getFolders($mailbox);
        } catch (Throwable $e) {
            return new JSONResponse([
                'success' => false,
                'error' => 'Die IMAP-Ordner konnten nicht geladen werden.',
            ], 500);
        }

        return new JSONResponse([
            'success' => true,
            'mailbox' => [
                'id' => $mailbox->getId(),
                'name' => $mailbox->getName(),
                'email' => $mailbox->getEmail(),
            ],
            'folders' => $folders,
        ]);
    }
}