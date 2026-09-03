<?php

declare(strict_types=1);

namespace OCA\SharedMail\Controller;

use InvalidArgumentException;
use OCA\SharedMail\AppInfo\Application;
use OCA\SharedMail\Service\MailboxAccessService;
use OCA\SharedMail\Service\ReplySendService;
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

            $result =
                $this
                    ->replySendService
                    ->sendReply(
                        $mailbox,
                        $folder,
                        $uid,
                        $to,
                        $subject,
                        $html
                    );

            return new JSONResponse([
                'success' =>
                    true,

                'message' =>
                    'Die Antwort wurde erfolgreich gesendet.',

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

                'warning' =>
                    $result['warning'],
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
            /*
             * Absichtlich keine technischen SMTP-,
             * IMAP- oder Passwortdetails an den Browser.
             */
            return new JSONResponse(
                [
                    'success' =>
                        false,

                    'message' =>
                        'Die Antwort konnte nicht gesendet werden.',
                ],
                500
            );
        }
    }
}