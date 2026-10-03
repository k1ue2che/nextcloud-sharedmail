<?php

declare(strict_types=1);

namespace OCA\SharedMail\Controller;

use OCA\SharedMail\AppInfo\Application;
use OCA\SharedMail\Service\MailboxAccessService;
use OCA\SharedMail\Service\MailboxPermission;
use OCA\SharedMail\Service\MessageMoveService;
use OCA\SharedMail\Service\MessageStateMoveService;
use OCA\SharedMail\Service\PersonalReadStateMoveService;
use OCP\IL10N;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

class MoveController extends Controller
{
    public function __construct(
        IRequest $request,
        private readonly MailboxAccessService $mailboxAccessService,
        private readonly MessageMoveService $messageMoveService,
        private readonly PersonalReadStateMoveService $personalReadStateMoveService,
        private readonly MessageStateMoveService $messageStateMoveService,
        private readonly IL10N $l,
    ) {
        parent::__construct(
            Application::APP_ID,
            $request
        );
    }


    #[NoAdminRequired]
    public function message(
        int $id,
        int $uid,
        string $folder = 'INBOX',
        string $target = ''
    ): JSONResponse {
        if ($uid <= 0) {
            return new JSONResponse(
                [
                    'success' =>
                        false,

                    'message' =>
                        $this->l->t('Invalid message ID.'),
                ],
                400
            );
        }

        $folder =
            trim(
                $folder
            );

        $target =
            trim(
                $target
            );

        if ($folder === '') {
            $folder =
                'INBOX';
        }

        if ($target === '') {
            return new JSONResponse(
                [
                    'success' =>
                        false,

                    'message' =>
                        $this->l->t('Please select a target folder.'),
                ],
                400
            );
        }

        if ($folder === $target) {
            return new JSONResponse(
                [
                    'success' =>
                        false,

                    'message' =>
                        $this->l->t('The message is already in this folder.'),
                ],
                400
            );
        }

        try {
            $mailbox =
                $this
                    ->mailboxAccessService
                    ->getAccessibleMailbox(
                        $id,
                        MailboxPermission::MOVE
                    );

            if ($mailbox === null) {
                return new JSONResponse(
                    [
                        'success' =>
                            false,

                        'message' =>
                            $this->l->t('No permission to move messages in this mailbox.'),
                    ],
                    403
                );
            }

            /*
             * Zuerst echtes IMAP-MOVE.
             */
            $move =
                $this
                    ->messageMoveService
                    ->move(
                        $mailbox,
                        $folder,
                        $uid,
                        $target
                    );
        } catch (Throwable $e) {
            return new JSONResponse(
                [
                    'success' =>
                        false,

                    'message' =>
                        $this->l->t('The message could not be moved.'),

                    /*
                     * Entwicklungsphase:
                     * Hilft uns beim Testen.
                     *
                     * Vor einem öffentlichen Release
                     * sollten wir details entfernen.
                     */
                    'details' =>
                        $e->getMessage(),
                ],
                500
            );
        }


        /*
         * Das IMAP-MOVE ist jetzt bereits erfolgt.
         *
         * Fehler beim Übertragen zusätzlicher
         * Shared-Mail-Zustände dürfen deshalb nicht
         * behaupten, die Mail sei nicht verschoben
         * worden.
         */
        $readStateTransferred =
            true;

        try {
            $readStateTransferred =
                $this
                    ->personalReadStateMoveService
                    ->transfer(
                        $id,
                        $move['sourceFolder'],
                        $move['sourceUid'],
                        $move['targetFolder'],
                        $move['targetUid']
                    );
        } catch (Throwable) {
            $readStateTransferred =
                false;
        }


        $workflowStateTransferred =
            true;

        try {
            $workflowStateTransferred =
                $this
                    ->messageStateMoveService
                    ->transfer(
                        $id,
                        $move['sourceFolder'],
                        $move['sourceUid'],
                        $move['targetFolder'],
                        $move['targetUid']
                    );
        } catch (Throwable) {
            $workflowStateTransferred =
                false;
        }


        return new JSONResponse(
            [
                'success' =>
                    true,

                'sourceFolder' =>
                    $move['sourceFolder'],

                'sourceUid' =>
                    $move['sourceUid'],

                'targetFolder' =>
                    $move['targetFolder'],

                'targetUid' =>
                    $move['targetUid'],

                'readStateTransferred' =>
                    $readStateTransferred,

                'workflowStateTransferred' =>
                    $workflowStateTransferred,
            ]
        );
    }
}