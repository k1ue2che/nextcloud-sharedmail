<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

use Horde_Imap_Client_Socket;
use OCA\SharedMail\Db\Mailbox;
use RuntimeException;
use Throwable;

class SentMessageService
{
    public function __construct(
        private readonly CredentialService $credentialService,
        private readonly MailboxImapService $mailboxImapService,
    ) {
    }

    /**
     * Speichert eine bereits versendete RFC822-Nachricht
     * im IMAP-Sent-Ordner.
     *
     * @return array{
     *     success: bool,
     *     folder: string|null,
     *     message: string
     * }
     */
    public function appendToSent(
        Mailbox $mailbox,
        string $rawMessage,
    ): array {
        if ($rawMessage === '') {
            return [
                'success' => false,
                'folder' => null,
                'message' =>
                    'Die gesendete Nachricht konnte nicht für IMAP aufbereitet werden.',
            ];
        }

        try {
            $sentFolder =
                $this->findSentFolder(
                    $mailbox
                );

            if ($sentFolder === null) {
                return [
                    'success' => false,
                    'folder' => null,
                    'message' =>
                        'Es wurde kein IMAP-Ordner für gesendete Nachrichten gefunden.',
                ];
            }

            $client =
                $this->createClient(
                    $mailbox
                );

            try {
                $client->login();

                /*
                 * Horde append():
                 *
                 * Jede einzufügende Nachricht ist
                 * ein eigener Eintrag im data-Array.
                 *
                 * \Seen:
                 * Eine selbst gesendete Mail ist natürlich
                 * bereits gelesen.
                 */
                $client->append(
                    $sentFolder,
                    [
                        [
                            'data' =>
                                $rawMessage,

                            'flags' => [
                                '\\Seen',
                            ],
                        ],
                    ]
                );

                return [
                    'success' => true,
                    'folder' => $sentFolder,
                    'message' =>
                        'Die Nachricht wurde im Gesendet-Ordner gespeichert.',
                ];
            } finally {
                try {
                    $client->logout();
                } catch (Throwable) {
                    // Verbindung wird ohnehin geschlossen.
                }
            }
        } catch (Throwable) {
            /*
             * Wichtig:
             *
             * Diese Methode wird erst NACH erfolgreichem
             * SMTP-Versand aufgerufen.
             *
             * Deshalb niemals eine Exception bis zum
             * Controller durchreichen – sonst könnte
             * der Browser einen erneuten Versand anbieten.
             */
            return [
                'success' => false,
                'folder' => null,
                'message' =>
                    'Die Mail wurde versendet, konnte aber nicht im Gesendet-Ordner gespeichert werden.',
            ];
        }
    }

    /**
     * Markiert die Originalmail einer Antwort mit \Answered.
     */
    public function markAnswered(
        Mailbox $mailbox,
        string $folder,
        int $uid,
    ): bool {
        $folder =
            trim(
                $folder
            );

        if ($folder === '') {
            $folder =
                'INBOX';
        }

        if ($uid <= 0) {
            return false;
        }

        try {
            $client =
                $this->createClient(
                    $mailbox
                );

            try {
                $client->login();

                /*
                 * false = UID und nicht Sequenznummer.
                 */
                $ids =
                    $client->getIdsOb(
                        $uid,
                        false
                    );

                $client->store(
                    $folder,
                    [
                        'ids' =>
                            $ids,

                        'add' => [
                            '\\Answered',
                        ],
                    ]
                );

                return true;
            } finally {
                try {
                    $client->logout();
                } catch (Throwable) {
                    // Verbindung wird ohnehin geschlossen.
                }
            }
        } catch (Throwable) {
            /*
             * Auch hier:
             * SMTP ist zu diesem Zeitpunkt bereits erfolgt.
             */
            return false;
        }
    }

    private function findSentFolder(
        Mailbox $mailbox,
    ): ?string {
        /*
         * Wir verwenden bewusst die bereits vorhandene
         * Ordner-/Special-Use-Erkennung von Shared Mail.
         *
         * Damit funktioniert auch:
         *
         * Sent
         * Gesendet
         * Sent Messages
         * INBOX/Sent
         * usw.
         */
        $folders =
            $this
                ->mailboxImapService
                ->getFolders(
                    $mailbox
                );

        foreach ($folders as $folder) {
            if (
                strtolower(
                    (string)(
                        $folder['specialUse']
                        ?? ''
                    )
                ) === 'sent'
            ) {
                $name =
                    trim(
                        (string)(
                            $folder['name']
                            ?? ''
                        )
                    );

                if ($name !== '') {
                    return $name;
                }
            }
        }

        /*
         * Fallback für Server ohne SPECIAL-USE.
         */
        $fallbackNames = [
            'Sent',
            'Sent Messages',
            'Gesendet',
            'INBOX/Sent',
            'INBOX/Gesendet',
        ];

        foreach ($fallbackNames as $fallbackName) {
            foreach ($folders as $folder) {
                if (
                    strcasecmp(
                        (string)(
                            $folder['name']
                            ?? ''
                        ),
                        $fallbackName
                    ) === 0
                ) {
                    return (string)$folder['name'];
                }
            }
        }

        return null;
    }

    private function createClient(
        Mailbox $mailbox,
    ): Horde_Imap_Client_Socket {
        $password =
            $this->credentialService->decrypt(
                (string)$mailbox->getImapPassword()
            );

        return new Horde_Imap_Client_Socket([
            'username' =>
                $mailbox->getImapUsername(),

            'password' =>
                $password,

            'hostspec' =>
                $mailbox->getImapHost(),

            'port' =>
                $mailbox->getImapPort(),

            'secure' =>
                $this->normalizeSecurity(
                    $mailbox->getImapSecurity()
                ),

            'timeout' =>
                20,

            'context' => [
                'ssl' => [
                    'verify_peer' =>
                        true,

                    'verify_peer_name' =>
                        true,
                ],
            ],
        ]);
    }

    private function normalizeSecurity(
        string $security,
    ): string|false {
        return match (
            strtolower(
                trim($security)
            )
        ) {
            'ssl' =>
                'ssl',

            'tls' =>
                'tls',

            'none' =>
                false,

            default =>
                false,
        };
    }
}