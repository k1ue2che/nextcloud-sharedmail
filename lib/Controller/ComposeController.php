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
             * Ab hier findet der eigentliche Versand statt.
             *
             * ComposeSendService darf nur dann werfen,
             * wenn SMTP selbst NICHT erfolgreich war.
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
             * Wenn wir hier angekommen sind, wurde die
             * Nachricht bereits erfolgreich per SMTP
             * verschickt.
             *
             * Alles danach ist nur noch Nachbearbeitung.
             */
            $warnings = [];

            if (
                isset($result['warning'])
                && is_string($result['warning'])
                && $result['warning'] !== ''
            ) {
                $warnings[] =
                    $result['warning'];
            }

            $draftDeleted =
                false;

            /*
             * Gespeicherten Draft entfernen.
             *
             * WICHTIG:
             *
             * Diese Operation darf einen bereits
             * erfolgreichen Mailversand niemals in
             * einen HTTP-500-Fehler verwandeln.
             */
            if ($draftUid > 0) {
                try {
                    $draftResult =
                        $this
                            ->draftMessageService
                            ->deleteDraft(
                                $mailbox,
                                $draftUid
                            );

                    $draftDeleted =
                        (bool)(
                            $draftResult['success']
                            ?? false
                        );

                    if (!$draftDeleted) {
                        $draftWarning =
                            trim(
                                (string)(
                                    $draftResult['message']
                                    ?? ''
                                )
                            );

                        if ($draftWarning === '') {
                            $draftWarning =
                                'Die Nachricht wurde gesendet, der Entwurf konnte aber nicht entfernt werden.';
                        }

                        $warnings[] =
                            $draftWarning;
                    }
                } catch (Throwable) {
                    /*
                     * SMTP war bereits erfolgreich.
                     *
                     * Deshalb ausschließlich Warnung,
                     * niemals success=false.
                     */
                    $warnings[] =
                        'Die Nachricht wurde gesendet, der Entwurf konnte aber nicht entfernt werden.';
                }
            }

            return new JSONResponse([
                'success' =>
                    true,

                'message' =>
                    'Die Nachricht wurde erfolgreich gesendet.',

                'messageId' =>
                    (string)(
                        $result['messageId']
                        ?? ''
                    ),

                'recipients' =>
                    is_array(
                        $result['recipients']
                        ?? null
                    )
                        ? $result['recipients']
                        : [],

                'sentSaved' =>
                    (bool)(
                        $result['sentSaved']
                        ?? false
                    ),

                'sentFolder' =>
                    $result['sentFolder']
                    ?? null,

                'draftUid' =>
                    $draftUid,

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
            /*
             * Validierungsfehler vor dem SMTP-Versand.
             */
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
            /*
             * Dieser Block darf nur noch erreicht werden,
             * wenn der eigentliche Versand nicht
             * erfolgreich abgeschlossen wurde.
             */
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