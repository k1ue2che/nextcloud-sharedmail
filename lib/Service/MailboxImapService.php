<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

use Horde_Imap_Client;
use Horde_Imap_Client_Fetch_Query;
use Horde_Imap_Client_Mailbox;
use Horde_Imap_Client_Socket;
use Horde_Mime_Part;
use OCA\SharedMail\Db\Mailbox;
use RuntimeException;
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

    public function getMessage(
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

        $client = $this->createClient($mailbox);

        try {
            $client->login();

            /*
            * false = UID, nicht Sequenznummer.
            */
            $ids = $client->getIdsOb(
                $uid,
                false
            );

            /*
            * Zuerst nur Metadaten + MIME-Struktur.
            */
            $query =
                new Horde_Imap_Client_Fetch_Query();

            $query->envelope();
            $query->flags();
            $query->imapDate();
            $query->size();
            $query->uid();
            $query->structure();

            $results = $client->fetch(
                $folder,
                $query,
                [
                    'ids' => $ids,
                ]
            );

            $message = $results->first();

            if (
                $message === null
                || $message === false
            ) {
                throw new RuntimeException(
                    'Nachricht wurde nicht gefunden.'
                );
            }

            $envelope =
                $message->getEnvelope();

            $structure =
                $message->getStructure();

            if (
                !$structure
                instanceof Horde_Mime_Part
            ) {
                throw new RuntimeException(
                    'Die MIME-Struktur konnte nicht gelesen werden.'
                );
            }

            $structure->buildMimeIds();

            $flags = array_map(
                static fn ($flag): string =>
                    strtolower((string)$flag),
                $message->getFlags()
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

            [
                $timestamp,
                $dateIso,
            ] = $this->extractMessageDate(
                $envelope->date ?? null,
                $message
            );

            /*
            * HTML bevorzugen.
            *
            * Falls kein HTML vorhanden:
            * Plaintext verwenden.
            */
            $htmlBodyId =
                $structure->findBody('html');

            $plainBodyId =
                $structure->findBody('plain');

            $bodyId = null;
            $bodyType = 'text';

            if (
                $htmlBodyId !== null
                && $htmlBodyId !== false
            ) {
                $bodyId =
                    (string)$htmlBodyId;

                $bodyType = 'html';
            } elseif (
                $plainBodyId !== null
                && $plainBodyId !== false
            ) {
                $bodyId =
                    (string)$plainBodyId;

                $bodyType = 'text';
            } else {
                /*
                * Fallback für ungewöhnliche MIME-Mails.
                */
                $fallbackBodyId =
                    $structure->findBody();

                if (
                    $fallbackBodyId !== null
                    && $fallbackBodyId !== false
                ) {
                    $bodyId =
                        (string)$fallbackBodyId;

                    $fallbackPart =
                        $structure->getPart(
                            $bodyId
                        );

                    if (
                        $fallbackPart
                        instanceof Horde_Mime_Part
                        && strtolower(
                            $fallbackPart->getSubType()
                        ) === 'html'
                    ) {
                        $bodyType = 'html';
                    }
                }
            }

            $bodyContent = '';

            /*
            * Jetzt wirklich nur den benötigten
            * Body-Part laden.
            */
            if ($bodyId !== null) {
                $bodyPart =
                    $structure->getPart(
                        $bodyId
                    );

                if (
                    $bodyPart
                    instanceof Horde_Mime_Part
                ) {
                    $bodyQuery =
                        new Horde_Imap_Client_Fetch_Query();

                    $bodyQuery->bodyPart(
                        $bodyId,
                        [
                            /*
                            * Transfer-Encoding nach
                            * Möglichkeit serverseitig
                            * dekodieren.
                            */
                            'decode' => true,

                            /*
                            * GANZ WICHTIG:
                            * Nachricht NICHT als gelesen
                            * markieren.
                            */
                            'peek' => true,
                        ]
                    );

                    $bodyResults =
                        $client->fetch(
                            $folder,
                            $bodyQuery,
                            [
                                'ids' => $ids,
                            ]
                        );

                    $bodyMessage =
                        $bodyResults->first();

                    if (
                        $bodyMessage !== null
                        && $bodyMessage !== false
                    ) {
                        $rawBody =
                            $bodyMessage
                                ->getBodyPart(
                                    $bodyId
                                );

                        if (is_resource($rawBody)) {
                            $rawBody =
                                stream_get_contents(
                                    $rawBody
                                );
                        }

                        $rawBody =
                            (string)$rawBody;

                        /*
                        * Nicht jeder IMAP-Server kann
                        * transfer-dekodieren.
                        *
                        * Dann lassen wir Horde Mime
                        * das lokal erledigen.
                        */
                        if (
                            !$bodyMessage
                                ->getBodyPartDecode(
                                    $bodyId
                                )
                        ) {
                            $partCopy =
                                clone $bodyPart;

                            $partCopy
                                ->setContents(
                                    $rawBody
                                );

                            $rawBody =
                                (string)$partCopy
                                    ->getContents();
                        }

                        $bodyContent =
                            $this->convertMessageBodyToUtf8(
                                $rawBody,
                                $bodyPart->getCharset()
                            );
                    }
                }
            }

            /*
            * Body-Parts nicht zusätzlich
            * als Anhänge anzeigen.
            */
            $excludedBodyIds =
                array_values(
                    array_unique(
                        array_filter(
                            [
                                $bodyId,

                                $htmlBodyId !== null
                                && $htmlBodyId !== false
                                    ? (string)$htmlBodyId
                                    : null,

                                $plainBodyId !== null
                                && $plainBodyId !== false
                                    ? (string)$plainBodyId
                                    : null,
                            ],
                            static fn (
                                ?string $value
                            ): bool =>
                                $value !== null
                                && $value !== ''
                        )
                    )
                );

            $attachments =
                $this->extractMessageAttachments(
                    $structure,
                    $excludedBodyIds
                );

            return [
                'uid' =>
                    (int)$message->getUid(),

                'folder' =>
                    $folder,

                'subject' =>
                    $subject,

                'from' =>
                    $this->extractFirstAddress(
                        $envelope->from_decoded
                        ?? $envelope->from
                        ?? []
                    ),

                'to' =>
                    $this->extractMessageAddresses(
                        $envelope->to_decoded
                        ?? $envelope->to
                        ?? []
                    ),

                'cc' =>
                    $this->extractMessageAddresses(
                        $envelope->cc_decoded
                        ?? $envelope->cc
                        ?? []
                    ),

                'timestamp' =>
                    $timestamp,

                'date' =>
                    $dateIso,

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

                'body' => [
                    'type' =>
                        $bodyType,

                    'content' =>
                        $bodyContent,
                ],

                'attachments' =>
                    $attachments,
            ];
        } finally {
            try {
                $client->logout();
            } catch (Throwable) {
                // Verbindung wird ohnehin geschlossen.
            }
        }
    }


    /**
     * Liest alle Empfänger aus einem
     * Horde-RFC822-Adressobjekt.
     *
     * @return array<int, array{
     *     name: string,
     *     email: string
     * }>
     */
    private function extractMessageAddresses(
        iterable $addresses,
    ): array {
        $result = [];

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

                if ($email === '') {
                    try {
                        $local = trim(
                            (string)(
                                $address->mailbox
                                ?? ''
                            )
                        );

                        $host = trim(
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
                        // Kein nutzbarer Fallback.
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
                    && !empty(
                        $address['mailbox']
                    )
                    && !empty(
                        $address['host']
                    )
                ) {
                    $email =
                        $address['mailbox']
                        . '@'
                        . $address['host'];
                }
            }

            if (
                $name === ''
                && $email === ''
            ) {
                continue;
            }

            $result[] = [
                'name' => $name,
                'email' => $email,
            ];
        }

        return $result;
    }


    /**
     * @param string[] $excludedBodyIds
     *
     * @return array<int, array{
     *     mimeId: string,
     *     name: string,
     *     contentType: string,
     *     size: int,
     *     inline: bool,
     *     contentId: string|null
     * }>
     */
    private function extractMessageAttachments(
        Horde_Mime_Part $structure,
        array $excludedBodyIds,
    ): array {
        $attachments = [];

        /*
        * MIME-Struktur rekursiv durchlaufen.
        */
        foreach (
            $structure->partIterator()
            as $part
        ) {
            if (
                !$part
                instanceof Horde_Mime_Part
            ) {
                continue;
            }

            $mimeId =
                (string)$part->getMimeId();

            if (
                $mimeId === ''
                || in_array(
                    $mimeId,
                    $excludedBodyIds,
                    true
                )
            ) {
                continue;
            }

            /*
            * Multipart selbst ist kein Anhang.
            */
            if (
                strtolower(
                    $part->getPrimaryType()
                ) === 'multipart'
            ) {
                continue;
            }

            $disposition =
                strtolower(
                    (string)$part
                        ->getDisposition()
                );

            $name =
                trim(
                    (string)$part
                        ->getName(true)
                );

            $contentId =
                $part->getContentId();

            /*
            * Datei, Inline-Datei oder benannter
            * MIME-Part.
            */
            $isAttachment =
                $disposition === 'attachment'
                || $name !== ''
                || $contentId !== null;

            if (!$isAttachment) {
                continue;
            }

            if ($name === '') {
                $name =
                    $contentId !== null
                        ? 'Inline-Datei'
                        : 'Anhang';
            }

            $attachments[] = [
                /*
                * Diese ID brauchen wir später
                * zum Herunterladen des Anhangs.
                */
                'mimeId' =>
                    $mimeId,

                'name' =>
                    $name,

                'contentType' =>
                    (string)$part->getType(),

                'size' =>
                    (int)$part
                        ->getBytes(true),

                'inline' =>
                    $disposition === 'inline'
                    || $contentId !== null,

                'contentId' =>
                    $contentId,
            ];
        }

        return $attachments;
    }


    private function convertMessageBodyToUtf8(
        string $content,
        ?string $charset,
    ): string {
        if ($content === '') {
            return '';
        }

        $charset =
            trim((string)$charset);

        if ($charset === '') {
            $charset = 'UTF-8';
        }

        if (
            strcasecmp(
                $charset,
                'UTF-8'
            ) === 0
        ) {
            if (
                mb_check_encoding(
                    $content,
                    'UTF-8'
                )
            ) {
                return $content;
            }

            return mb_convert_encoding(
                $content,
                'UTF-8',
                'Windows-1252'
            );
        }

        try {
            return mb_convert_encoding(
                $content,
                'UTF-8',
                $charset
            );
        } catch (Throwable) {
            /*
            * Fallback für kaputte oder falsch
            * deklarierte ältere E-Mails.
            */
            return mb_convert_encoding(
                $content,
                'UTF-8',
                'UTF-8, Windows-1252, ISO-8859-1'
            );
        }
    }


    /**
     * @return array{0: int|null, 1: string|null}
     */
    private function extractMessageDate(
        mixed $date,
        mixed $message,
    ): array {
        if (
            !is_object($date)
            || !method_exists(
                $date,
                'getTimestamp'
            )
        ) {
            try {
                $date =
                    $message->getImapDate();
            } catch (Throwable) {
                $date = null;
            }
        }

        if (
            !is_object($date)
            || !method_exists(
                $date,
                'getTimestamp'
            )
        ) {
            return [
                null,
                null,
            ];
        }

        try {
            $timestamp =
                (int)$date->getTimestamp();

            $dateIso =
                method_exists(
                    $date,
                    'format'
                )
                    ? $date->format(
                        DATE_ATOM
                    )
                    : null;

            return [
                $timestamp,
                $dateIso,
            ];
        } catch (Throwable) {
            return [
                null,
                null,
            ];
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