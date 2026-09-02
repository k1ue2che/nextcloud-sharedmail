<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

use Horde_Imap_Client_Data_Fetch;
use Horde_Imap_Client_Fetch_Query;
use Horde_Imap_Client_Socket;
use Horde_Mime_Headers;
use OCA\SharedMail\Db\Mailbox;
use RuntimeException;
use Throwable;

class ReplyContextService
{
    public function __construct(
        private readonly CredentialService $credentialService,
    ) {
    }

    /**
     * @return array{
     *     messageId: string,
     *     references: string,
     *     inReplyTo: string
     * }
     */
    public function getContext(
        Mailbox $mailbox,
        string $folder,
        int $uid,
    ): array {
        $folder = trim($folder);

        if ($folder === '') {
            $folder = 'INBOX';
        }

        if ($uid <= 0) {
            throw new RuntimeException(
                'Ungültige Nachrichten-UID.'
            );
        }

        $client = $this->createClient(
            $mailbox
        );

        try {
            $client->login();

            /*
             * false = UID und nicht IMAP-Sequenznummer.
             */
            $ids = $client->getIdsOb(
                $uid,
                false
            );

            $query =
                new Horde_Imap_Client_Fetch_Query();

            /*
             * Wir lesen die RFC-Header der Originalmail.
             *
             * peek=true ist wichtig:
             * Das darf das globale IMAP-\Seen nicht ändern.
             */
            $query->headerText([
                'peek' => true,
            ]);

            $results =
                $client->fetch(
                    $folder,
                    $query,
                    [
                        'ids' => $ids,
                    ]
                );

            $message =
                $results->first();

            if (
                $message === null
                || $message === false
            ) {
                throw new RuntimeException(
                    'Die Originalnachricht wurde nicht gefunden.'
                );
            }

            $headerText =
                $message->getHeaderText();

            if (is_resource($headerText)) {
                $headerText =
                    stream_get_contents(
                        $headerText
                    );
            }

            $headerText =
                (string)$headerText;

            if ($headerText === '') {
                return [
                    'messageId' => '',
                    'references' => '',
                    'inReplyTo' => '',
                ];
            }

            $headers =
                Horde_Mime_Headers::parseHeaders(
                    $headerText
                );

            return [
                'messageId' =>
                    $this->getHeaderValue(
                        $headers,
                        'Message-ID'
                    ),

                'references' =>
                    $this->getHeaderValue(
                        $headers,
                        'References'
                    ),

                'inReplyTo' =>
                    $this->getHeaderValue(
                        $headers,
                        'In-Reply-To'
                    ),
            ];
        } finally {
            try {
                $client->logout();
            } catch (Throwable) {
                // Verbindung wird ohnehin geschlossen.
            }
        }
    }

    private function getHeaderValue(
        Horde_Mime_Headers $headers,
        string $name,
    ): string {
        try {
            $value =
                $headers->getValue(
                    $name
                );

            if (is_array($value)) {
                $value =
                    implode(
                        ' ',
                        array_map(
                            static fn ($item): string =>
                                (string)$item,
                            $value
                        )
                    );
            }

            return trim(
                (string)$value
            );
        } catch (Throwable) {
            return '';
        }
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
                15,

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