<?php

declare(strict_types=1);

namespace OCA\SharedMail\Controller;

use OCA\SharedMail\AppInfo\Application;
use OCA\SharedMail\Service\AttachmentService;
use OCA\SharedMail\Service\MailboxAccessService;
use OCA\SharedMail\Service\MailboxPermission;
use OCP\IL10N;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Throwable;

class AttachmentController extends Controller
{
    /**
     * MIME-Typen, die wir sicher inline anzeigen.
     *
     * SVG bleibt bewusst auÃŸen vor.
     */
    private const INLINE_CONTENT_TYPES = [
        'application/pdf',

        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/avif',
    ];

    public function __construct(
        IRequest $request,
        private readonly MailboxAccessService $mailboxAccessService,
        private readonly AttachmentService $attachmentService,
        private readonly IL10N $l,
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
            $attachment =
                $this->loadAttachment(
                    $id,
                    $uid,
                    $folder,
                    $mimeId
                );

            if ($attachment instanceof Response) {
                return $attachment;
            }

            return new DataDownloadResponse(
                $attachment['content'],
                $attachment['name'],
                $attachment['contentType']
            );
        } catch (Throwable $e) {
            return new JSONResponse(
                [
                    'success' =>
                        false,

                    'message' =>
                        $this->l->t('The attachment could not be downloaded.'),

                ],
                500
            );
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function view(
        int $id,
        int $uid,
        string $folder = 'INBOX',
        string $mimeId = '',
    ): Response {
        try {
            $attachment =
                $this->loadAttachment(
                    $id,
                    $uid,
                    $folder,
                    $mimeId
                );

            if ($attachment instanceof Response) {
                return $attachment;
            }

            $contentType =
                strtolower(
                    trim(
                        (string)$attachment['contentType']
                    )
                );

            /*
             * Nur ausdrÃ¼cklich erlaubte Dateitypen
             * inline darstellen.
             */
            if (
                !in_array(
                    $contentType,
                    self::INLINE_CONTENT_TYPES,
                    true
                )
            ) {
                return new JSONResponse(
                    [
                        'success' =>
                            false,

                        'message' =>
                            $this->l->t('This file type cannot be opened directly. Please download it.'),
                    ],
                    415
                );
            }

            $response =
                new DataDisplayResponse(
                    $attachment['content'],
                    200,
                    [
                        'Content-Type' =>
                            $contentType,

                        /*
                         * Browser darf keinen anderen
                         * MIME-Typ erraten.
                         */
                        'X-Content-Type-Options' =>
                            'nosniff',
                    ]
                );

            /*
             * DataDisplayResponse verwendet ohnehin
             * inline. Wir setzen zusÃ¤tzlich einen
             * sauberen Dateinamen.
             */
            $response->addHeader(
                'Content-Disposition',
                $this->buildInlineContentDisposition(
                    $attachment['name']
                )
            );

            return $response;
        } catch (Throwable $e) {
            return new JSONResponse(
                [
                    'success' =>
                        false,

                    'message' =>
                        $this->l->t('The attachment could not be opened.'),

                ],
                500
            );
        }
    }

    /**
     * @return array{
     *     content:string,
     *     name:string,
     *     contentType:string,
     *     size:int
     * }|Response
     */
    private function loadAttachment(
        int $id,
        int $uid,
        string $folder,
        string $mimeId,
    ): array|Response {
        if ($uid <= 0) {
            return new JSONResponse(
                [
                    'success' => false,
                    'message' =>
                        $this->l->t('Invalid message ID.'),
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
                        $this->l->t('The attachment MIME ID is missing.'),
                ],
                400
            );
        }

        /*
         * Zugriff auf das Shared-Mail-Postfach
         * immer zuerst prÃ¼fen.
         */
        $mailbox =
            $this
                ->mailboxAccessService
                ->getAccessibleMailbox(
                    $id,
                    MailboxPermission::READ
                );

        if ($mailbox === null) {
            return new JSONResponse(
                [
                    'success' => false,
                    'message' =>
                        $this->l->t('No read permission for this mailbox.'),
                ],
                403
            );
        }

        return $this
            ->attachmentService
            ->getAttachment(
                $mailbox,
                $folder,
                $uid,
                $mimeId
            );
    }

    private function buildInlineContentDisposition(
        string $filename
    ): string {
        /*
         * Header-Injection verhindern.
         */
        $filename =
            str_replace(
                [
                    "\r",
                    "\n",
                    "\0",
                ],
                '',
                trim($filename)
            );

        if ($filename === '') {
            $filename =
                $this->l->t('Attachment');
        }

        /*
         * ASCII-Fallback fÃ¼r alte Clients.
         */
        $fallback =
            preg_replace(
                '/[^A-Za-z0-9._ -]/',
                '_',
                $filename
            );

        if (
            $fallback === null
            || trim($fallback) === ''
        ) {
            $fallback =
                $this->l->t('Attachment');
        }

        $fallback =
            str_replace(
                [
                    '\\',
                    '"',
                ],
                '_',
                $fallback
            );

        return
            'inline; filename="'
            . $fallback
            . '"; filename*=UTF-8\'\''
            . rawurlencode(
                $filename
            );
    }
}
