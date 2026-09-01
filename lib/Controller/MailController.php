<?php

declare(strict_types=1);

namespace OCA\SharedMail\Controller;

use OCA\SharedMail\AppInfo\Application;
use OCA\SharedMail\Service\MailboxAccessService;
use OCA\SharedMail\Service\MailboxImapService;
use OCA\SharedMail\Service\PersonalFolderCountService;
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
        private readonly PersonalFolderCountService $personalFolderCountService,
    ) {
        parent::__construct(
            Application::APP_ID,
            $request
        );
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function folders(
        int $id
    ): JSONResponse {
        try {
            $mailbox =
                $this
                    ->mailboxAccessService
                    ->getAccessibleMailbox(
                        $id
                    );

            if ($mailbox === null) {
                return new JSONResponse(
                    [
                        'success' => false,
                        'message' =>
                            'Kein Zugriff auf dieses Postfach.',
                    ],
                    403
                );
            }

            $folders =
                $this
                    ->mailboxImapService
                    ->getFolders(
                        $mailbox
                    );

            $folders =
                $this
                    ->personalFolderCountService
                    ->apply(
                        $mailbox,
                        $id,
                        $folders
                    );

            return new JSONResponse(
                [
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
                ]
            );
        } catch (Throwable) {
            return new JSONResponse(
                [
                    'success' => false,
                    'message' =>
                        'Die Ordner konnten nicht geladen werden.',
                ],
                500
            );
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function messages(
        int $id,
        string $folder = 'INBOX',
        int $limit = 50,
        int $offset = 0
    ): JSONResponse {
        try {
            $mailbox =
                $this
                    ->mailboxAccessService
                    ->getAccessibleMailbox(
                        $id
                    );

            if ($mailbox === null) {
                return new JSONResponse(
                    [
                        'success' => false,
                        'message' =>
                            'Kein Zugriff auf dieses Postfach.',
                    ],
                    403
                );
            }

            $folder =
                trim($folder);

            if ($folder === '') {
                $folder =
                    'INBOX';
            }

            $result =
                $this
                    ->mailboxImapService
                    ->getMessages(
                        $mailbox,
                        $folder,
                        $limit,
                        $offset
                    );

            $result['messages'] =
                $this
                    ->personalReadStateService
                    ->applyToMessages(
                        $id,
                        (string)$result['folder'],
                        $result['messages']
                    );

            return new JSONResponse(
                [
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
                ]
            );
        } catch (Throwable) {
            return new JSONResponse(
                [
                    'success' => false,
                    'message' =>
                        'Die Nachrichten konnten nicht geladen werden.',
                ],
                500
            );
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function message(
        int $id,
        int $uid,
        string $folder = 'INBOX'
    ): JSONResponse {
        try {
            if ($uid <= 0) {
                return new JSONResponse(
                    [
                        'success' => false,
                        'message' =>
                            'Ungültige Nachrichten-ID.',
                    ],
                    400
                );
            }

            $mailbox =
                $this
                    ->mailboxAccessService
                    ->getAccessibleMailbox(
                        $id
                    );

            if ($mailbox === null) {
                return new JSONResponse(
                    [
                        'success' => false,
                        'message' =>
                            'Kein Zugriff auf dieses Postfach.',
                    ],
                    403
                );
            }

            $folder =
                trim($folder);

            if ($folder === '') {
                $folder =
                    'INBOX';
            }

            $message =
                $this
                    ->mailboxImapService
                    ->getMessage(
                        $mailbox,
                        $folder,
                        $uid
                    );

            $imapSeen =
                (bool)(
                    $message['seen']
                    ?? false
                );

            $message['imapSeen'] =
                $imapSeen;

            $message['seen'] =
                $this
                    ->personalReadStateService
                    ->resolveIsRead(
                        $id,
                        $folder,
                        $uid,
                        $imapSeen
                    );

            return new JSONResponse(
                [
                    'success' => true,
                    'message' =>
                        $message,
                ]
            );
        } catch (Throwable) {
            return new JSONResponse(
                [
                    'success' => false,
                    'message' =>
                        'Die Nachricht konnte nicht geladen werden.',
                ],
                500
            );
        }
    }

    #[NoAdminRequired]
    public function markRead(
        int $id,
        int $uid,
        string $folder = 'INBOX'
    ): JSONResponse {
        try {
            if ($uid <= 0) {
                return new JSONResponse(
                    [
                        'success' => false,
                        'message' =>
                            'Ungültige Nachrichten-ID.',
                    ],
                    400
                );
            }

            $mailbox =
                $this
                    ->mailboxAccessService
                    ->getAccessibleMailbox(
                        $id
                    );

            if ($mailbox === null) {
                return new JSONResponse(
                    [
                        'success' => false,
                        'message' =>
                            'Kein Zugriff auf dieses Postfach.',
                    ],
                    403
                );
            }

            $folder =
                trim($folder);

            if ($folder === '') {
                $folder =
                    'INBOX';
            }

            $saved =
                $this
                    ->personalReadStateService
                    ->markRead(
                        $id,
                        $folder,
                        $uid
                    );

            if (!$saved) {
                return new JSONResponse(
                    [
                        'success' => false,
                        'message' =>
                            'Der Lesestatus konnte nicht gespeichert werden.',
                    ],
                    400
                );
            }

            return new JSONResponse(
                [
                    'success' => true,

                    'uid' =>
                        $uid,

                    'folder' =>
                        $folder,

                    'seen' =>
                        true,
                ]
            );
        } catch (Throwable) {
            return new JSONResponse(
                [
                    'success' => false,
                    'message' =>
                        'Der Lesestatus konnte nicht gespeichert werden.',
                ],
                500
            );
        }
    }

    #[NoAdminRequired]
    public function markUnread(
        int $id,
        int $uid,
        string $folder = 'INBOX'
    ): JSONResponse {
        try {
            if ($uid <= 0) {
                return new JSONResponse(
                    [
                        'success' => false,
                        'message' =>
                            'Ungültige Nachrichten-ID.',
                    ],
                    400
                );
            }

            $mailbox =
                $this
                    ->mailboxAccessService
                    ->getAccessibleMailbox(
                        $id
                    );

            if ($mailbox === null) {
                return new JSONResponse(
                    [
                        'success' => false,
                        'message' =>
                            'Kein Zugriff auf dieses Postfach.',
                    ],
                    403
                );
            }

            $folder =
                trim($folder);

            if ($folder === '') {
                $folder =
                    'INBOX';
            }

            $saved =
                $this
                    ->personalReadStateService
                    ->markUnread(
                        $id,
                        $folder,
                        $uid
                    );

            if (!$saved) {
                return new JSONResponse(
                    [
                        'success' => false,
                        'message' =>
                            'Der Lesestatus konnte nicht gespeichert werden.',
                    ],
                    400
                );
            }

            return new JSONResponse(
                [
                    'success' => true,

                    'uid' =>
                        $uid,

                    'folder' =>
                        $folder,

                    'seen' =>
                        false,
                ]
            );
        } catch (Throwable) {
            return new JSONResponse(
                [
                    'success' => false,
                    'message' =>
                        'Der Lesestatus konnte nicht gespeichert werden.',
                ],
                500
            );
        }
    }
}