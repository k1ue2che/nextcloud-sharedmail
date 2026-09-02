<?php

declare(strict_types=1);

namespace OCA\SharedMail\Controller;

use OCA\SharedMail\AppInfo\Application;
use OCA\SharedMail\Service\AttachmentService;
use OCA\SharedMail\Service\MailboxAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Throwable;

class AttachmentController extends Controller
{
    public function __construct(
        IRequest $request,
        private readonly MailboxAccessService $mailboxAccessService,
        private readonly AttachmentService $attachmentService,
    ) {
        parent::__construct(
            Application::APP_ID,
            $request
        );
    }


    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function download(
        int $id,
        int $uid,
        string $folder = 'INBOX',
        string $mimeId = '',
    ): Response {
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

            $folder =
                trim($folder);

            if ($folder === '') {
                $folder =
                    'INBOX';
            }

            $mimeId =
                trim($mimeId);

            if ($mimeId === '') {
                return new JSONResponse(
                    [
                        'success' => false,
                        'message' =>
                            'Die MIME-ID des Anhangs fehlt.',
                    ],
                    400
                );
            }

            /*
             * Dieselbe Zugriffsprüfung wie bei
             * Nachrichten und Ordnern.
             */
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

            $attachment =
                $this
                    ->attachmentService
                    ->getAttachment(
                        $mailbox,
                        $folder,
                        $uid,
                        $mimeId
                    );

            return new DataDownloadResponse(
                $attachment['content'],
                $attachment['name'],
                $attachment['contentType']
            );
        } catch (Throwable $e) {
            /*
             * Während unserer Entwicklungsphase
             * lassen wir details noch drin.
             */
            return new JSONResponse(
                [
                    'success' =>
                        false,

                    'message' =>
                        'Der Anhang konnte nicht heruntergeladen werden.',

                    'details' =>
                        $e->getMessage(),
                ],
                500
            );
        }
    }
}