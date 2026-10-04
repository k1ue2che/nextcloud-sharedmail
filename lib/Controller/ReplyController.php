<?php

declare(strict_types=1);

namespace OCA\SharedMail\Controller;

use InvalidArgumentException;
use OCA\SharedMail\AppInfo\Application;
use OCA\SharedMail\Service\AttachmentUploadService;
use OCA\SharedMail\Service\DraftMessageService;
use OCA\SharedMail\Service\MailboxAccessService;
use OCA\SharedMail\Service\MailboxPermission;
use OCA\SharedMail\Service\ReplySendService;
use OCA\SharedMail\Service\DraftReadService;
use OCP\IL10N;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

class ReplyController extends Controller
{
    public function __construct(
        IRequest $request,
        private readonly MailboxAccessService $mailboxAccessService,
        private readonly ReplySendService $replySendService,
        private readonly AttachmentUploadService $attachmentUploadService,
        private readonly DraftMessageService $draftMessageService,
        private readonly DraftReadService $draftReadService,
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
        int $uid,
        string $folder = 'INBOX',
        string $to = '',
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
                        MailboxPermission::REPLY
                    );

            if ($mailbox === null) {
                return new JSONResponse(
                    [
                        'success' =>
                            false,

                        'message' =>
                            $this->l->t('No permission to reply in this mailbox.'),
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
                    ->replySendService
                    ->sendReply(
                        $mailbox,
                        $folder,
                        $uid,
                        $to,
                        $subject,
                        $html,
                        $attachments
                    );

            $draftDeleted =
                false;

            $warnings = [];

            if (!empty($result['warning'])) {
                $warnings[] =
                    $result['warning'];
            }

            /*
             * Erst nach erfolgreichem SMTP-Versand
             * den gespeicherten Antwort-Draft entfernen.
             */
            if ($draftUid > 0) {
                try {
                    $draft =
                        $this
                            ->draftReadService
                            ->getDraft(
                                $mailbox,
                                $draftUid
                            );

                    $draftSourceFolder =
                        trim(
                            (string)(
                                $draft['sourceFolder']
                                ?? ''
                            )
                        );

                    $draftSourceUid =
                        (int)(
                            $draft['sourceUid']
                            ?? 0
                        );

                    $draftKind =
                        strtolower(
                            trim(
                                (string)(
                                    $draft['kind']
                                    ?? ''
                                )
                            )
                        );

                    $normalizedFolder =
                        trim($folder);

                    if ($normalizedFolder === '') {
                        $normalizedFolder =
                            'INBOX';
                    }

                    $matchingReplyDraft =
                        ($draft['draft'] ?? false) === true
                        && $draftKind === 'reply'
                        && $draftSourceFolder === $normalizedFolder
                        && $draftSourceUid === $uid;

                    if ($matchingReplyDraft) {
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
                    } else {
                        $warnings[] =
                            $this->l->t(
                                'The reply was sent, but the associated draft was not removed because it does not match the original message.'
                            );
                    }
                } catch (Throwable) {
                    /*
                    * SMTP war bereits erfolgreich.
                    * Ein Problem mit dem Draft darf den
                    * Versand deshalb nicht nachträglich
                    * als fehlgeschlagen melden.
                    */
                    $warnings[] =
                        $this->l->t(
                            'The reply was sent, but the associated draft could not be verified or removed.'
                        );
                }
            }

            return new JSONResponse([
                'success' =>
                    true,

                'message' =>
                    $this->l->t('The reply was sent successfully.'),

                'messageId' =>
                    $result['messageId'],

                'recipient' =>
                    $result['recipient'],

                'sentSaved' =>
                    $result['sentSaved'],

                'sentFolder' =>
                    $result['sentFolder'],

                'answeredMarked' =>
                    $result['answeredMarked'],

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
                        $this->l->t('The reply could not be sent.'),
                ],
                500
            );
        }
    }
}