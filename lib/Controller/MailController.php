<?php

declare(strict_types=1);

namespace OCA\SharedMail\Controller;

use OCA\SharedMail\AppInfo\Application;
use OCA\SharedMail\Service\MailboxAccessService;
use OCA\SharedMail\Service\MailboxImapService;
use OCA\SharedMail\Service\PersonalReadStateService;
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
        private readonly PersonalReadStateService $personalReadStateService,
    ) {
        parent::__construct(
            Application::APP_ID,
            $request
        );
    }


    /**
     * IMAP-Ordner eines freigegebenen Postfachs.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function folders(
        int $id,
    ): JSONResponse {
        $mailbox =
            $this->mailboxAccessService
                ->getAccessibleMailbox(
                    $id
                );

        if ($mailbox === null) {
            return new JSONResponse(
                [
                    'success' => false,
                    'error' =>
                        'Kein Zugriff auf dieses Postfach.',
                ],
                403
            );
        }

        try {
            $folders =
                $this->mailboxImapService
                    ->getFolders(
                        $mailbox
                    );
        } catch (Throwable) {
            return new JSONResponse(
                [
                    'success' => false,
                    'error' =>
                        'Die IMAP-Ordner konnten nicht geladen werden.',
                ],
                500
            );
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

            'folders' =>
                $folders,
        ]);
    }


    /**
     * Nachrichtenliste.
     *
     * MailboxImapService liefert zunächst den
     * tatsächlichen IMAP-\Seen-Zustand.
     *
     * Danach überschreibt PersonalReadStateService
     * diesen bei Bedarf mit dem persönlichen Zustand
     * des angemeldeten Nextcloud-Benutzers.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function messages(
        int $id,
        string $folder = 'INBOX',
        int $limit = 50,
        int $offset = 0,
    ): JSONResponse {
        $mailbox =
            $this->mailboxAccessService
                ->getAccessibleMailbox(
                    $id
                );

        if ($mailbox === null) {
            return new JSONResponse(
                [
                    'success' => false,
                    'error' =>
                        'Kein Zugriff auf dieses Postfach.',
                ],
                403
            );
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

            /*
             * Persönlichen Lesestatus auf
             * die Nachrichtenliste anwenden.
             */
            $result['messages'] =
                $this->personalReadStateService
                    ->applyToMessages(
                        $id,
                        $result['folder'],
                        $result['messages']
                    );
        } catch (Throwable) {
            return new JSONResponse(
                [
                    'success' => false,
                    'error' =>
                        'Die Nachrichten konnten nicht geladen werden.',
                ],
                500
            );
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


    /**
     * Einzelne Nachricht anzeigen.
     *
     * Wichtig:
     * Diese GET-Methode markiert NICHT selbst als gelesen.
     *
     * Das geschieht anschließend über POST /read.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function message(
        int $id,
        int $uid,
        string $folder = 'INBOX',
    ): JSONResponse {
        $mailbox =
            $this->mailboxAccessService
                ->getAccessibleMailbox(
                    $id
                );

        if ($mailbox === null) {
            return new JSONResponse(
                [
                    'success' => false,
                    'error' =>
                        'Kein Zugriff auf dieses Postfach.',
                ],
                403
            );
        }

        try {
            $message =
                $this->mailboxImapService
                    ->getMessage(
                        $mailbox,
                        $folder,
                        $uid
                    );

            /*
             * MailboxImapService liefert hier
             * den IMAP-\Seen-Status.
             */
            $imapSeen =
                (bool)(
                    $message['seen']
                    ?? false
                );

            /*
             * Originalstatus für spätere
             * Diagnose/Synchronisation aufheben.
             */
            $message['imapSeen'] =
                $imapSeen;

            /*
             * Für die Darstellung den
             * persönlichen Status verwenden.
             */
            $message['seen'] =
                $this->personalReadStateService
                    ->resolveIsRead(
                        $id,
                        $folder,
                        $uid,
                        $imapSeen
                    );
        } catch (Throwable) {
            return new JSONResponse(
                [
                    'success' => false,
                    'error' =>
                        'Die Nachricht konnte nicht geladen werden.',
                ],
                500
            );
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


    /**
     * Nachricht für den aktuell angemeldeten
     * Nextcloud-Benutzer persönlich als gelesen markieren.
     *
     * Ändert NICHT das IMAP-\Seen-Flag.
     */
    #[NoAdminRequired]
    public function markRead(
        int $id,
        int $uid,
        string $folder = 'INBOX',
    ): JSONResponse {
        $mailbox =
            $this->mailboxAccessService
                ->getAccessibleMailbox(
                    $id
                );

        if ($mailbox === null) {
            return new JSONResponse(
                [
                    'success' => false,
                    'error' =>
                        'Kein Zugriff auf dieses Postfach.',
                ],
                403
            );
        }

        if (
            $uid <= 0
            || trim($folder) === ''
        ) {
            return new JSONResponse(
                [
                    'success' => false,
                    'error' =>
                        'Ungültige Nachricht.',
                ],
                400
            );
        }

        try {
            $success =
                $this->personalReadStateService
                    ->markRead(
                        $id,
                        $folder,
                        $uid
                    );
        } catch (Throwable) {
            return new JSONResponse(
                [
                    'success' => false,
                    'error' =>
                        'Der persönliche Lesestatus konnte nicht gespeichert werden.',
                ],
                500
            );
        }

        if (!$success) {
            return new JSONResponse(
                [
                    'success' => false,
                    'error' =>
                        'Der persönliche Lesestatus konnte nicht gespeichert werden.',
                ],
                500
            );
        }

        return new JSONResponse([
            'success' => true,
            'uid' => $uid,
            'folder' => $folder,
            'seen' => true,
        ]);
    }


    /**
     * Nachricht für den aktuell angemeldeten
     * Nextcloud-Benutzer persönlich als ungelesen markieren.
     *
     * Auch hier wird IMAP nicht verändert.
     */
    #[NoAdminRequired]
    public function markUnread(
        int $id,
        int $uid,
        string $folder = 'INBOX',
    ): JSONResponse {
        $mailbox =
            $this->mailboxAccessService
                ->getAccessibleMailbox(
                    $id
                );

        if ($mailbox === null) {
            return new JSONResponse(
                [
                    'success' => false,
                    'error' =>
                        'Kein Zugriff auf dieses Postfach.',
                ],
                403
            );
        }

        if (
            $uid <= 0
            || trim($folder) === ''
        ) {
            return new JSONResponse(
                [
                    'success' => false,
                    'error' =>
                        'Ungültige Nachricht.',
                ],
                400
            );
        }

        try {
            $success =
                $this->personalReadStateService
                    ->markUnread(
                        $id,
                        $folder,
                        $uid
                    );
        } catch (Throwable) {
            return new JSONResponse(
                [
                    'success' => false,
                    'error' =>
                        'Der persönliche Lesestatus konnte nicht gespeichert werden.',
                ],
                500
            );
        }

        if (!$success) {
            return new JSONResponse(
                [
                    'success' => false,
                    'error' =>
                        'Der persönliche Lesestatus konnte nicht gespeichert werden.',
                ],
                500
            );
        }

        return new JSONResponse([
            'success' => true,
            'uid' => $uid,
            'folder' => $folder,
            'seen' => false,
        ]);
    }
}