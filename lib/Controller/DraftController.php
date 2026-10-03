<?php

declare(strict_types=1);

namespace OCA\SharedMail\Controller;

use InvalidArgumentException;
use OCA\SharedMail\AppInfo\Application;
use OCA\SharedMail\Service\AttachmentUploadService;
use OCA\SharedMail\Service\DraftMessageService;
use OCA\SharedMail\Service\DraftReadService;
use OCA\SharedMail\Service\MailboxAccessService;
use OCA\SharedMail\Service\MailboxPermission;
use OCP\IL10N;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use RuntimeException;
use Throwable;

class DraftController extends Controller
{
    public function __construct(
        IRequest $request,
        private readonly MailboxAccessService $mailboxAccessService,
        private readonly DraftMessageService $draftMessageService,
        private readonly DraftReadService $draftReadService,
        private readonly AttachmentUploadService $attachmentUploadService,
        private readonly IL10N $l,
    ) {
        parent::__construct(
            Application::APP_ID,
            $request
        );
    }

    /**
     * Einen vorhandenen IMAP-Draft laden.
     *
     * Zum Lesen eines Drafts genügt READ.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function get(
        int $id,
        int $uid,
    ): JSONResponse {
        try {
            if ($uid <= 0) {
                return new JSONResponse(
                    [
                        'success' => false,
                        'message' =>
                            $this->l->t('Invalid draft ID.'),
                    ],
                    400
                );
            }

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

            $draft =
                $this
                    ->draftReadService
                    ->getDraft(
                        $mailbox,
                        $uid
                    );

            return new JSONResponse([
                'success' => true,
                'draft' => $draft,
            ]);
        } catch (RuntimeException $e) {
            return new JSONResponse(
                [
                    'success' => false,
                    'message' => $e->getMessage(),
                ],
                404
            );
        } catch (Throwable) {
            return new JSONResponse(
                [
                    'success' => false,
                    'message' =>
                        $this->l->t('The draft could not be loaded.'),
                ],
                500
            );
        }
    }

    /**
     * Einen neuen Draft speichern oder
     * einen vorhandenen Draft ersetzen.
     *
     * Normaler Compose-Draft:
     * COMPOSE
     *
     * Antwort-Draft:
     * REPLY
     *
     * Bei bestehenden Drafts wird zusätzlich
     * der tatsächliche Typ vom IMAP-Server
     * kontrolliert.
     */
    #[NoAdminRequired]
    public function save(
        int $id,
        string $to = '',
        string $cc = '',
        string $bcc = '',
        string $subject = '',
        string $html = '',
        string $sourceFolder = '',
        int $sourceUid = 0,
        int $draftUid = 0,
    ): JSONResponse {
        try {
            if ($draftUid < 0) {
                return new JSONResponse(
                    [
                        'success' => false,
                        'message' =>
                            $this->l->t('Invalid draft ID.'),
                    ],
                    400
                );
            }

            if ($sourceUid < 0) {
                return new JSONResponse(
                    [
                        'success' => false,
                        'message' =>
                            $this->l->t('Invalid original message ID.'),
                    ],
                    400
                );
            }

            $sourceFolder =
                trim(
                    $sourceFolder
                );

            $hasSourceFolder =
                $sourceFolder !== '';

            $hasSourceUid =
                $sourceUid > 0;

            /*
             * sourceFolder und sourceUid gehören
             * immer zusammen.
             */
            if (
                $hasSourceFolder
                !== $hasSourceUid
            ) {
                return new JSONResponse(
                    [
                        'success' => false,
                        'message' =>
                            $this->l->t('The original message of the reply draft is incomplete.'),
                    ],
                    400
                );
            }

            $requestIsReply =
                $hasSourceFolder
                && $hasSourceUid;

            /*
             * Für einen neuen Draft ist die
             * Entscheidung eindeutig.
             *
             * Bei einem vorhandenen Draft kann ein
             * älterer Client eventuell die Source-
             * Informationen nicht erneut mitsenden.
             *
             * Deshalb erlauben wir dort zunächst
             * COMPOSE oder REPLY und kontrollieren
             * anschließend den tatsächlichen Draft.
             */
            if ($requestIsReply) {
                $initialPermission =
                    MailboxPermission::REPLY;
            } elseif (
                $draftUid > 0
                && !$this
                    ->mailboxAccessService
                    ->hasPermission(
                        $id,
                        MailboxPermission::COMPOSE
                    )
                && $this
                    ->mailboxAccessService
                    ->hasPermission(
                        $id,
                        MailboxPermission::REPLY
                    )
            ) {
                $initialPermission =
                    MailboxPermission::REPLY;
            } else {
                $initialPermission =
                    MailboxPermission::COMPOSE;
            }

            $mailbox =
                $this
                    ->mailboxAccessService
                    ->getAccessibleMailbox(
                        $id,
                        $initialPermission
                    );

            if ($mailbox === null) {
                return new JSONResponse(
                    [
                        'success' => false,
                        'message' =>
                            $this->l->t('No permission to save this draft.'),
                    ],
                    403
                );
            }

            /*
             * Vorhandenen Draft kontrollieren.
             *
             * Dadurch kann ein Antwort-Draft nicht
             * durch Weglassen von sourceFolder /
             * sourceUid zu einem normalen Compose-
             * Draft umgedeutet werden.
             */
            if ($draftUid > 0) {
                $existingDraft =
                    $this
                        ->draftReadService
                        ->getDraft(
                            $mailbox,
                            $draftUid
                        );

                $existingKind =
                    strtolower(
                        trim(
                            (string)(
                                $existingDraft['kind']
                                ?? 'compose'
                            )
                        )
                    );

                $existingSourceFolder =
                    trim(
                        (string)(
                            $existingDraft['sourceFolder']
                            ?? ''
                        )
                    );

                $existingSourceUid =
                    (int)(
                        $existingDraft['sourceUid']
                        ?? 0
                    );

                $existingIsReply =
                    $existingKind === 'reply'
                    || (
                        $existingSourceFolder !== ''
                        && $existingSourceUid > 0
                    );

                if ($existingIsReply) {
                    /*
                     * Ein bestehender Antwort-Draft
                     * benötigt immer REPLY.
                     */
                    if (
                        !$this
                            ->mailboxAccessService
                            ->hasPermission(
                                $id,
                                MailboxPermission::REPLY
                            )
                    ) {
                        return new JSONResponse(
                            [
                                'success' => false,
                                'message' =>
                                    $this->l->t('No permission to edit this reply draft.'),
                            ],
                            403
                        );
                    }

                    if (
                        $existingSourceFolder === ''
                        || $existingSourceUid <= 0
                    ) {
                        return new JSONResponse(
                            [
                                'success' => false,
                                'message' =>
                                    $this->l->t('The reply draft does not contain a valid original message.'),
                            ],
                            400
                        );
                    }

                    /*
                     * Falls der Client die Source-
                     * Informationen nicht mitsendet,
                     * übernehmen wir sie sicher aus
                     * dem bestehenden Draft.
                     */
                    if (!$requestIsReply) {
                        $sourceFolder =
                            $existingSourceFolder;

                        $sourceUid =
                            $existingSourceUid;

                        $requestIsReply =
                            true;
                    } elseif (
                        $sourceFolder
                            !== $existingSourceFolder
                        || $sourceUid
                            !== $existingSourceUid
                    ) {
                        /*
                         * Die Zuordnung eines
                         * bestehenden Antwort-Drafts
                         * darf nicht verändert werden.
                         */
                        return new JSONResponse(
                            [
                                'success' => false,
                                'message' =>
                                    $this->l->t('The original message of a reply draft may not be changed.'),
                            ],
                            400
                        );
                    }
                } else {
                    /*
                     * Ein normaler Compose-Draft
                     * benötigt immer COMPOSE.
                     */
                    if (
                        !$this
                            ->mailboxAccessService
                            ->hasPermission(
                                $id,
                                MailboxPermission::COMPOSE
                            )
                    ) {
                        return new JSONResponse(
                            [
                                'success' => false,
                                'message' =>
                                    $this->l->t('No permission to edit this draft.'),
                            ],
                            403
                        );
                    }

                    /*
                     * Einen vorhandenen normalen
                     * Compose-Draft nicht nachträglich
                     * in einen Reply-Draft umwandeln.
                     */
                    if ($requestIsReply) {
                        return new JSONResponse(
                            [
                                'success' => false,
                                'message' =>
                                    $this->l->t('An existing draft cannot later be converted into a reply draft.'),
                            ],
                            400
                        );
                    }
                }
            }

            /*
             * Für neue Drafts noch einmal explizit
             * das endgültig benötigte Recht prüfen.
             */
            if ($draftUid === 0) {
                $requiredPermission =
                    $requestIsReply
                        ? MailboxPermission::REPLY
                        : MailboxPermission::COMPOSE;

                if (
                    !$this
                        ->mailboxAccessService
                        ->hasPermission(
                            $id,
                            $requiredPermission
                        )
                ) {
                    return new JSONResponse(
                        [
                            'success' => false,
                            'message' =>
                                $requestIsReply
                                    ? $this->l->t('No permission to save reply drafts.')
                                    : $this->l->t('No permission to compose messages.'),
                        ],
                        403
                    );
                }
            }

            $attachments =
                $this
                    ->attachmentUploadService
                    ->getUploadedAttachments(
                        $this->request
                    );

            $result =
                $this
                    ->draftMessageService
                    ->saveDraft(
                        $mailbox,
                        $to,
                        $cc,
                        $bcc,
                        $subject,
                        $html,
                        $attachments,
                        $sourceFolder,
                        $sourceUid,
                        $draftUid
                    );

            return new JSONResponse([
                'success' => true,

                'message' =>
                    $result['replaced']
                        ? $this->l->t('The draft was updated.')
                        : $this->l->t('The draft was saved.'),

                'draftFolder' =>
                    $result['folder'],

                'draftUid' =>
                    $result['uid'],

                'messageId' =>
                    $result['messageId'],

                'replaced' =>
                    $result['replaced'],

                'warning' =>
                    $result['warning'],
            ]);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                [
                    'success' => false,
                    'message' => $e->getMessage(),
                ],
                400
            );
        } catch (RuntimeException $e) {
            return new JSONResponse(
                [
                    'success' => false,
                    'message' => $e->getMessage(),
                ],
                500
            );
        } catch (Throwable) {
            return new JSONResponse(
                [
                    'success' => false,
                    'message' =>
                        $this->l->t('The draft could not be saved.'),
                ],
                500
            );
        }
    }
}