<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

use Horde_Imap_Client;
use Horde_Imap_Client_Fetch_Query;
use Horde_Imap_Client_Mailbox;
use Horde_Imap_Client_Socket;
use OCA\SharedMail\Db\Mailbox;
use Throwable;

class MailboxImapService
{
    public function __construct(
        private readonly CredentialService $credentialService,
    ) {
    }

    /**
     * @return array<int, array{
     *     name: string,
     *     label: string,
     *     delimiter: string|null,
     *     attributes: array,
     *     specialUse: string|null,
     *     selectable: bool,
     *     messages: int|null,
     *     unseen: int|null
     * }>
     */
    public function getFolders(
        Mailbox $mailbox,
    ): array {
        $client = $this->createClient($mailbox);

        try {
            $client->login();

            $mailboxes = $client->listMailboxes(
                '*',
                Horde_Imap_Client::MBOX_ALL,
                [
                    'attributes' => true,
                    'delimiter' => true,
                    'children' => true,
                    'special_use' => true,
                    'sort' => true,
                ]
            );

            $folders = [];

            foreach ($mailboxes as $entry) {
                $mailboxObject = $entry['mailbox'] ?? null;

                if ($mailboxObject instanceof Horde_Imap_Client_Mailbox) {
                    $name = $mailboxObject->utf8;
                } else {
                    $name = (string)$mailboxObject;
                }

                if ($name === '') {
                    continue;
                }

                $delimiter = isset($entry['delimiter'])
                    ? (string)$entry['delimiter']
                    : null;

                $attributes = array_map(
                    static fn ($attribute): string =>
                        strtolower((string)$attribute),
                    $entry['attributes'] ?? []
                );

                $selectable =
                    !in_array('\\noselect', $attributes, true)
                    && !in_array('\\nonexistent', $attributes, true);

                $messages = null;
                $unseen = null;

                if ($selectable) {
                    try {
                        $status = $client->status(
                            $mailboxObject,
                            Horde_Imap_Client::STATUS_MESSAGES
                            | Horde_Imap_Client::STATUS_UNSEEN
                        );

                        $messages = isset($status['messages'])
                            ? (int)$status['messages']
                            : 0;

                        $unseen = isset($status['unseen'])
                            ? (int)$status['unseen']
                            : 0;
                    } catch (Throwable) {
                        // Einzelner Ordner darf fehlschlagen.
                    }
                }

                $folders[] = [
                    'name' => $name,
                    'label' => $this->getFolderLabel(
                        $name,
                        $delimiter
                    ),
                    'delimiter' => $delimiter,
                    'attributes' => $attributes,
                    'specialUse' => $this->detectSpecialUse(
                        $name,
                        $attributes
                    ),
                    'selectable' => $selectable,
                    'messages' => $messages,
                    'unseen' => $unseen,
                ];
            }

            usort(
                $folders,
                fn (array $a, array $b): int =>
                    $this->compareFolders($a, $b)
            );

            return $folders;
        } finally {
            try {
                $client->logout();
            } catch (Throwable) {
                // Verbindung wird ohnehin beendet.
            }
        }
    }

    /**
     * Lädt eine Seite der Nachrichtenliste.
     *
     * Wichtig:
     * Hier wird NICHT der komplette Nachrichtentext geladen.
     *
     * @return array{
     *     folder: string,
     *     total: int,
     *     offset: int,
     *     limit: int,
     *     hasMore: bool,
     *     messages: array<int, array<string, mixed>>
     * }
     */
    public function getMessages(
        Mailbox $mailbox,
        string $folder,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $folder = trim($folder);

        if ($folder === '') {
            $folder = 'INBOX';
        }

        /*
         * Verhindert versehentlich riesige Requests.
         */
        $limit = max(
            1,
            min($limit, 100)
        );

        $offset = max(
            0,
            $offset
        );

        $client = $this->createClient($mailbox);

        try {
            $client->login();

            $status = $client->status(
                $folder,
                Horde_Imap_Client::STATUS_MESSAGES
            );

            $total = isset($status['messages'])
                ? (int)$status['messages']
                : 0;

            if ($total === 0 || $offset >= $total) {
                return [
                    'folder' => $folder,
                    'total' => $total,
                    'offset' => $offset,
                    'limit' => $limit,
                    'hasMore' => false,
                    'messages' => [],
                ];
            }

            /*
             * IMAP-Sequenznummern laufen von 1 bis Anzahl Nachrichten.
             *
             * Wir beginnen bewusst hinten, damit die neuesten
             * Nachrichten zuerst geladen werden.
             *
             * Beispiel:
             *
             * total  = 100
             * limit  = 50
             * offset = 0
             *
             * -> Sequenzen 51 bis 100
             */
            $endSequence =
                $total - $offset;

            $startSequence = max(
                1,
                $endSequence - $limit + 1
            );

            $ids = $client->getIdsOb(
                $startSequence . ':' . $endSequence,
                true
            );

            $query =
                new Horde_Imap_Client_Fetch_Query();

            $query->envelope();
            $query->flags();
            $query->imapDate();
            $query->size();
            $query->uid();
            $query->seq();

            $results = $client->fetch(
                $folder,
                $query,
                [
                    'ids' => $ids,
                ]
            );

            $messages = [];

            foreach ($results as $message) {
                $envelope =
                    $message->getEnvelope();

                $flags = array_map(
                    static fn ($flag): string =>
                        strtolower((string)$flag),
                    $message->getFlags()
                );

                $sender =
                    $this->extractFirstAddress(
                        $envelope->from_decoded
                        ?? $envelope->from
                        ?? []
                    );

                $subject = trim(
                    (string)(
                        $envelope->subject_decoded
                        ?? $envelope->subject
                        ?? ''
                    )
                );

                if ($subject === '') {
                    $subject = '(Kein Betreff)';
                }

                /*
                 * Für die Anzeige bevorzugen wir das Datum
                 * aus dem Envelope.
                 *
                 * Wenn es fehlt oder kaputt ist, fällt die
                 * Anzeige auf INTERNALDATE zurück.
                 */
                $date =
                    $envelope->date
                    ?? null;

                if (
                    !is_object($date)
                    || !method_exists($date, 'getTimestamp')
                ) {
                    try {
                        $date =
                            $message->getImapDate();
                    } catch (Throwable) {
                        $date = null;
                    }
                }

                $timestamp = null;
                $dateIso = null;

                if (
                    is_object($date)
                    && method_exists($date, 'getTimestamp')
                ) {
                    try {
                        $timestamp =
                            (int)$date->getTimestamp();

                        if (method_exists($date, 'format')) {
                            $dateIso =
                                $date->format(DATE_ATOM);
                        }
                    } catch (Throwable) {
                        $timestamp = null;
                        $dateIso = null;
                    }
                }

                $messages[] = [
                    'uid' =>
                        (int)$message->getUid(),

                    /*
                     * Sequence bleibt nur für unsere Sortierung.
                     * Der Client soll später ausschließlich
                     * mit der stabilen UID arbeiten.
                     */
                    'sequence' =>
                        (int)$message->getSeq(),

                    'from' => $sender,

                    'subject' => $subject,

                    'timestamp' => $timestamp,

                    'date' => $dateIso,

                    'size' =>
                        (int)$message->getSize(),

                    'seen' =>
                        in_array(
                            '\\seen',
                            $flags,
                            true
                        ),

                    'flagged' =>
                        in_array(
                            '\\flagged',
                            $flags,
                            true
                        ),

                    'answered' =>
                        in_array(
                            '\\answered',
                            $flags,
                            true
                        ),

                    'draft' =>
                        in_array(
                            '\\draft',
                            $flags,
                            true
                        ),

                    'flags' => $flags,
                ];
            }

            /*
             * Neueste Nachricht zuerst.
             */
            usort(
                $messages,
                static fn (
                    array $a,
                    array $b
                ): int =>
                    $b['sequence']
                    <=>
                    $a['sequence']
            );

            /*
             * Sequenznummer nicht an den Browser geben.
             * Für alle Aktionen verwenden wir später UID.
             */
            foreach ($messages as &$message) {
                unset($message['sequence']);
            }

            unset($message);

            return [
                'folder' => $folder,
                'total' => $total,
                'offset' => $offset,
                'limit' => $limit,
                'hasMore' =>
                    ($offset + count($messages))
                    < $total,
                'messages' => $messages,
            ];
        } finally {
            try {
                $client->logout();
            } catch (Throwable) {
                // Verbindung wird ohnehin beendet.
            }
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

            'timeout' => 15,

            'context' => [
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ],
        ]);
    }

    private function normalizeSecurity(
        string $security,
    ): string|false {
        return match (
            strtolower(trim($security))
        ) {
            'ssl' => 'ssl',
            'tls' => 'tls',
            'none' => false,
            default => false,
        };
    }

    /**
     * @param iterable<mixed> $addresses
     *
     * @return array{
     *     name: string,
     *     email: string
     * }
     */
    private function extractFirstAddress(
        iterable $addresses,
    ): array {
        foreach ($addresses as $address) {
            $name = '';
            $email = '';

            if (is_object($address)) {
                try {
                    $name = trim(
                        (string)(
                            $address->personal
                            ?? ''
                        )
                    );
                } catch (Throwable) {
                    $name = '';
                }

                try {
                    $email = trim(
                        (string)(
                            $address->bare_address
                            ?? ''
                        )
                    );
                } catch (Throwable) {
                    $email = '';
                }

                /*
                 * Fallback für Address-Objekte,
                 * die bare_address nicht liefern.
                 */
                if ($email === '') {
                    try {
                        $local =
                            trim(
                                (string)(
                                    $address->mailbox
                                    ?? ''
                                )
                            );

                        $host =
                            trim(
                                (string)(
                                    $address->host
                                    ?? ''
                                )
                            );

                        if (
                            $local !== ''
                            && $host !== ''
                        ) {
                            $email =
                                $local
                                . '@'
                                . $host;
                        }
                    } catch (Throwable) {
                        // Fallback fehlgeschlagen.
                    }
                }
            } elseif (is_array($address)) {
                $name = trim(
                    (string)(
                        $address['personal']
                        ?? ''
                    )
                );

                $email = trim(
                    (string)(
                        $address['bare_address']
                        ?? ''
                    )
                );

                if (
                    $email === ''
                    && !empty($address['mailbox'])
                    && !empty($address['host'])
                ) {
                    $email =
                        $address['mailbox']
                        . '@'
                        . $address['host'];
                }
            }

            return [
                'name' => $name,
                'email' => $email,
            ];
        }

        return [
            'name' => '',
            'email' => '',
        ];
    }

    /**
     * @param string[] $attributes
     */
    private function detectSpecialUse(
        string $name,
        array $attributes,
    ): ?string {
        if (
            strcasecmp(
                $name,
                'INBOX'
            ) === 0
        ) {
            return 'inbox';
        }

        $map = [
            '\\drafts' => 'drafts',
            '\\sent' => 'sent',
            '\\trash' => 'trash',
            '\\junk' => 'junk',
            '\\archive' => 'archive',
            '\\all' => 'all',
            '\\flagged' => 'flagged',
        ];

        foreach (
            $map
            as $attribute => $specialUse
        ) {
            if (
                in_array(
                    $attribute,
                    $attributes,
                    true
                )
            ) {
                return $specialUse;
            }
        }

        return null;
    }

    private function getFolderLabel(
        string $name,
        ?string $delimiter,
    ): string {
        if (
            $delimiter === null
            || $delimiter === ''
        ) {
            return $name;
        }

        $position =
            strrpos(
                $name,
                $delimiter
            );

        if ($position === false) {
            return $name;
        }

        return substr(
            $name,
            $position
            + strlen($delimiter)
        );
    }

    private function compareFolders(
        array $a,
        array $b,
    ): int {
        if (
            $a['specialUse'] === 'inbox'
            && $b['specialUse'] !== 'inbox'
        ) {
            return -1;
        }

        if (
            $b['specialUse'] === 'inbox'
            && $a['specialUse'] !== 'inbox'
        ) {
            return 1;
        }

        return strcasecmp(
            $a['name'],
            $b['name']
        );
    }
}