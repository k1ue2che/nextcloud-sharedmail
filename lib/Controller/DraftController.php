<?php

declare(strict_types=1);

namespace OCA\SharedMail\Controller;

use InvalidArgumentException;
use OCA\SharedMail\AppInfo\Application;
use OCA\SharedMail\Service\AttachmentUploadService;
use OCA\SharedMail\Service\DraftMessageService;
use OCA\SharedMail\Service\MailboxAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
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
        private readonly AttachmentUploadService $attachmentUploadService,
    ) {
        parent::__construct(
            Application::APP_ID,
            $request
        );
    }

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
                        $sourceUid
                    );

            return new JSONResponse([
                'success' =>
                    true,

                'message' =>
                    'Der Entwurf wurde gespeichert.',

                'draftFolder' =>
                    $result['folder'],

                'draftUid' =>
                    $result['uid'],

                'messageId' =>
                    $result['messageId'],
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
        } catch (
            RuntimeException $e
        ) {
            /*
             * DraftMessageService liefert nur
             * bewusst formulierte Runtime-Meldungen
             * weiter, keine Horde-Interna.
             */
            return new JSONResponse(
                [
                    'success' =>
                        false,

                    'message' =>
                        $e->getMessage(),
                ],
                500
            );
        } catch (Throwable) {
            return new JSONResponse(
                [
                    'success' =>
                        false,

                    'message' =>
                        'Der Entwurf konnte nicht gespeichert werden.',
                ],
                500
            );
        }
    }
}