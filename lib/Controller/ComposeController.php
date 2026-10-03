<?php

declare(strict_types=1);

namespace OCA\SharedMail\Controller;

use InvalidArgumentException;
use OCA\SharedMail\AppInfo\Application;
use OCA\SharedMail\Service\AttachmentUploadService;
use OCA\SharedMail\Service\ComposeSendService;
use OCA\SharedMail\Service\DraftMessageService;
use OCA\SharedMail\Service\MailboxAccessService;
use OCA\SharedMail\Service\MailboxPermission;
use OCP\IL10N;
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
        private readonly IL10N $l,
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
                        $id,
                        MailboxPermission::COMPOSE
                    );

            if ($mailbox === null) {
                return new JSONResponse(
                    [
                        'success' =>
                            false,

                        'message' =>
                            $this->l->t('No permission to compose messages in this mailbox.'),
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
                                $this->l->t('The message was sent, but the draft could not be removed.');
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
                        $this->l->t('The message was sent, but the draft could not be removed.');
                }
            }

            return new JSONResponse([
                'success' =>
                    true,

                'message' =>
                    $this->l->t('The message was sent successfully.'),

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
                        $this->l->t('The message could not be sent.'),
                ],
                500
            );
        }
    }
}