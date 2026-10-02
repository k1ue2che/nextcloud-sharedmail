<?php

declare(strict_types=1);

namespace OCA\SharedMail\Controller;

use InvalidArgumentException;
use OCA\SharedMail\AppInfo\Application;
use OCA\SharedMail\Service\MailboxAccessService;
use OCA\SharedMail\Service\MailboxPermission;
use OCA\SharedMail\Service\MessageStateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

class MessageStateController extends Controller
{
    public function __construct(
        IRequest $request,
        private readonly MailboxAccessService $mailboxAccessService,
        private readonly MessageStateService $messageStateService,
    ) {
        parent::__construct(
            Application::APP_ID,
            $request
        );
    }


    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function get(
        int $id,
        int $uid,
        string $folder = ''
    ): JSONResponse {
        $folder =
            trim(
                $folder
            );

        if (
            $id <= 0
            || $uid <= 0
            || $folder === ''
        ) {
            return new JSONResponse(
                [
                    'success' =>
                        false,

                    'message' =>
                        'Ungültige Nachricht.',
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
                    'success' =>
                        false,

                    'message' =>
                        'Keine Leseberechtigung für dieses Postfach.',
                ],
                403
            );
        }

        try {
            $state =
                $this
                    ->messageStateService
                    ->getState(
                        $id,
                        $folder,
                        $uid
                    );

            return new JSONResponse(
                [
                    'success' =>
                        true,

                    'state' =>
                        $state,
                ]
            );
        } catch (Throwable $e) {
            return new JSONResponse(
                [
                    'success' =>
                        false,

                    'message' =>
                        'Der Nachrichtenstatus konnte nicht geladen werden.',
                ],
                500
            );
        }
    }


    #[NoAdminRequired]
    public function setStatus(
        int $id,
        int $uid,
        string $folder = '',
        string $status = ''
    ): JSONResponse {
        $folder =
            trim(
                $folder
            );

        $status =
            trim(
                $status
            );

        if (
            $id <= 0
            || $uid <= 0
            || $folder === ''
        ) {
            return new JSONResponse(
                [
                    'success' =>
                        false,

                    'message' =>
                        'Ungültige Nachricht.',
                ],
                400
            );
        }

        if ($status === '') {
            return new JSONResponse(
                [
                    'success' =>
                        false,

                    'message' =>
                        'Es wurde kein Nachrichtenstatus angegeben.',
                ],
                400
            );
        }

        $mailbox =
            $this
                ->mailboxAccessService
                ->getAccessibleMailbox(
                    $id,
                    MailboxPermission::CHANGE_STATUS
                );

        if ($mailbox === null) {
            return new JSONResponse(
                [
                    'success' =>
                        false,

                    'message' =>
                        'Keine Berechtigung zum Ändern des Nachrichtenstatus.',
                ],
                403
            );
        }

        try {
            $state =
                $this
                    ->messageStateService
                    ->setStatus(
                        $id,
                        $folder,
                        $uid,
                        $status
                    );

            return new JSONResponse(
                [
                    'success' =>
                        true,

                    'state' =>
                        $state,
                ]
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                [
                    'success' =>
                        false,

                    'message' =>
                        $e->getMessage(),
                ],
                400
            );
        } catch (Throwable $e) {
            return new JSONResponse(
                [
                    'success' =>
                        false,

                    'message' =>
                        'Der Nachrichtenstatus konnte nicht gespeichert werden.',
                ],
                500
            );
        }
    }
}