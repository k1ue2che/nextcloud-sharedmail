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
        $mailbox =
            $this->mailboxAccessService
                ->getAccessibleMailbox($id);

        if ($mailbox === null) {
            return new JSONResponse([
                'success' => false,
                'error' =>
                    'Kein Zugriff auf dieses Postfach.',
            ], 403);
        }

        try {
            $folders =
                $this->mailboxImapService
                    ->getFolders($mailbox);
        } catch (Throwable) {
            return new JSONResponse([
                'success' => false,
                'error' =>
                    'Die IMAP-Ordner konnten nicht geladen werden.',
            ], 500);
        }

        return new JSONResponse([
            'success' => true,

            'mailbox' => [
                'id' =>
                    $mailbox->getId(),

                'name' =>
                    $mailbox->getName(),

                'email' =>
                    $mailbox->getEmail(),
            ],

            'folders' => $folders,
        ]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function messages(
        int $id,
        string $folder = 'INBOX',
        int $limit = 50,
        int $offset = 0,
    ): JSONResponse {
        /*
         * Auch hier gilt:
         *
         * NIEMALS direkt MailboxMapper->find().
         *
         * Jeder IMAP-Endpunkt läuft zuerst
         * durch unsere Zugriffsprüfung.
         */
        $mailbox =
            $this->mailboxAccessService
                ->getAccessibleMailbox($id);

        if ($mailbox === null) {
            return new JSONResponse([
                'success' => false,
                'error' =>
                    'Kein Zugriff auf dieses Postfach.',
            ], 403);
        }

        try {
            $result =
                $this->mailboxImapService
                    ->getMessages(
                        $mailbox,
                        $folder,
                        $limit,
                        $offset
                    );
        } catch (Throwable) {
            return new JSONResponse([
                'success' => false,
                'error' =>
                    'Die Nachrichten konnten nicht geladen werden.',
            ], 500);
        }

        return new JSONResponse([
            'success' => true,

            'mailbox' => [
                'id' =>
                    $mailbox->getId(),

                'name' =>
                    $mailbox->getName(),

                'email' =>
                    $mailbox->getEmail(),
            ],

            'folder' =>
                $result['folder'],

            'total' =>
                $result['total'],

            'offset' =>
                $result['offset'],

            'limit' =>
                $result['limit'],

            'hasMore' =>
                $result['hasMore'],

            'messages' =>
                $result['messages'],
        ]);
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function message(
        int $id,
        int $uid,
        string $folder = 'INBOX',
    ): JSONResponse {
        /*
        * Auch der Mail-Viewer kommt niemals
        * an unserer Berechtigungsprüfung vorbei.
        */
        $mailbox =
            $this->mailboxAccessService
                ->getAccessibleMailbox($id);

        if ($mailbox === null) {
            return new JSONResponse([
                'success' => false,
                'error' =>
                    'Kein Zugriff auf dieses Postfach.',
            ], 403);
        }

        try {
            $message =
                $this->mailboxImapService
                    ->getMessage(
                        $mailbox,
                        $folder,
                        $uid
                    );
        } catch (Throwable) {
            return new JSONResponse([
                'success' => false,
                'error' =>
                    'Die Nachricht konnte nicht geladen werden.',
            ], 500);
        }

        return new JSONResponse([
            'success' => true,

            'mailbox' => [
                'id' =>
                    $mailbox->getId(),

                'name' =>
                    $mailbox->getName(),

                'email' =>
                    $mailbox->getEmail(),
            ],

            'message' =>
                $message,
        ]);
    }
}