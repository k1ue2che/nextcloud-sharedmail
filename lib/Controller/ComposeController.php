<?php

declare(strict_types=1);

namespace OCA\SharedMail\Controller;

use InvalidArgumentException;
use OCA\SharedMail\AppInfo\Application;
use OCA\SharedMail\Service\AttachmentUploadService;
use OCA\SharedMail\Service\ComposeSendService;
use OCA\SharedMail\Service\DraftMessageService;
use OCA\SharedMail\Service\MailboxAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

class ComposeController extends Controller
{
    public function __construct(
        IRequest $request,
        private readonly MailboxAccessService $mailboxAccessService,
        private readonly ComposeSendService $composeSendService,
        private readonly AttachmentUploadService $attachmentUploadService,
        private readonly DraftMessageService $draftMessageService,
    ) {
        parent::__construct(
            Application::APP_ID,
            $request
        );
    }

    #[NoAdminRequired]
    public function send(
        int $id,
        string $to = '',
        string $cc = '',
        string $bcc = '',
        string $subject = '',
        string $html = '',
        int $draftUid = 0,
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
                        'success' =>
                            false,

                        'message' =>
                            'Kein Zugriff auf dieses Postfach.',
                    ],
                    403
                );
            }

            $attachments =
                $this
                    ->attachmentUploadService
                    ->getUploadedAttachments(
                        $this->request
                    );

            /*
             * Erst senden.
             */
            $result =
                $this
                    ->composeSendService
                    ->send(
                        $mailbox,
                        $to,
                        $cc,
                        $bcc,
                        $subject,
                        $html,
                        $attachments
                    );

            /*
             * SMTP ist an dieser Stelle bereits erfolgreich.
             *
             * Deshalb darf ein Fehler beim Draft-Löschen
             * niemals die gesamte Anfrage als fehlgeschlagen
             * zurückgeben.
             */
            $draftDeleted =
                false;

            $warnings = [];

            if (!empty($result['warning'])) {
                $warnings[] =
                    $result['warning'];
            }

            if ($draftUid > 0) {
                $draftResult =
                    $this
                        ->draftMessageService
                        ->deleteDraft(
                            $mailbox,
                            $draftUid
                        );

                $draftDeleted =
                    $draftResult['success'];

                if (
                    !$draftResult['success']
                    && !empty(
                        $draftResult['message']
                    )
                ) {
                    $warnings[] =
                        $draftResult['message'];
                }
            }

            return new JSONResponse([
                'success' =>
                    true,

                'message' =>
                    'Die Nachricht wurde erfolgreich gesendet.',

                'messageId' =>
                    $result['messageId'],

                'recipients' =>
                    $result['recipients'],

                'sentSaved' =>
                    $result['sentSaved'],

                'sentFolder' =>
                    $result['sentFolder'],

                'draftDeleted' =>
                    $draftDeleted,

                'warning' =>
                    $warnings !== []
                        ? implode(
                            ' ',
                            $warnings
                        )
                        : null,
            ]);
        } catch (
            InvalidArgumentException $e
        ) {
            return new JSONResponse(
                [
                    'success' =>
                        false,

                    'message' =>
                        $e->getMessage(),
                ],
                400
            );
        } catch (Throwable) {
            return new JSONResponse(
                [
                    'success' =>
                        false,

                    'message' =>
                        'Die Nachricht konnte nicht gesendet werden.',
                ],
                500
            );
        }
    }
}