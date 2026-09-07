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

class DraftReadService
{
    public function __construct(
        private readonly CredentialService $credentialService,
        private readonly MailboxImapService $mailboxImapService,
    ) {
    }

    /**
     * @return array{
     *     uid: int,
     *     folder: string,
     *     version: string,
     *     kind: string,
     *     to: string,
     *     cc: string,
     *     bcc: string,
     *     subject: string,
     *     sourceFolder: string,
     *     sourceUid: int,
     *     body: array{
     *         type: string,
     *         content: string
     *     },
     *     attachments: array<int, array<string, mixed>>,
     *     draft: bool
     * }
     */
    public function getDraft(
        Mailbox $mailbox,
        int $uid,
    ): array {
        if ($uid <= 0) {
            throw new RuntimeException(
                'Ungültige Entwurfs-ID.'
            );
        }

        $draftFolder =
            $this->findDraftFolder(
                $mailbox
            );

        if ($draftFolder === null) {
            throw new RuntimeException(
                'Es wurde kein IMAP-Ordner für Entwürfe gefunden.'
            );
        }

        /*
         * Body + MIME-Struktur + Anhänge verwenden
         * weiterhin den bereits funktionierenden
         * MailboxImapService.
         */
        $message =
            $this
                ->mailboxImapService
                ->getMessage(
                    $mailbox,
                    $draftFolder,
                    $uid
                );

        /*
         * Zusätzlich brauchen wir die echten Header,
         * weil dort unsere Shared-Mail-Draft-Metadaten
         * liegen.
         */
        [
            $headers,
            $isDraft,
        ] = $this->loadHeaders(
            $mailbox,
            $draftFolder,
            $uid
        );

        $version =
            trim(
                (string)(
                    $headers->getValue(
                        'X-SharedMail-Draft-Version'
                    )
                    ?? ''
                )
            );

        $kind =
            strtolower(
                trim(
                    (string)(
                        $headers->getValue(
                            'X-SharedMail-Draft-Kind'
                        )
                        ?? ''
                    )
                )
            );

        if ($kind !== 'reply') {
            $kind = 'compose';
        }

        /*
         * Unsere X-Header haben Vorrang.
         *
         * Das ist besonders wichtig bei unfertigen
         * Empfängern wie:
         *
         * mirko@
         *
         * So etwas steht absichtlich nicht im normalen
         * RFC-To-Header, soll im Editor aber trotzdem
         * wieder erscheinen.
         */
        $to =
            $this->decodeDraftHeader(
                $headers,
                'X-SharedMail-Draft-To'
            );

        if ($to === null) {
            $to =
                $this->formatAddresses(
                    $message['to']
                    ?? []
                );
        }

        $cc =
            $this->decodeDraftHeader(
                $headers,
                'X-SharedMail-Draft-Cc'
            );

        if ($cc === null) {
            $cc =
                $this->formatAddresses(
                    $message['cc']
                    ?? []
                );
        }

        $bcc =
            $this->decodeDraftHeader(
                $headers,
                'X-SharedMail-Draft-Bcc'
            );

        if ($bcc === null) {
            /*
             * Fallback für Drafts, die nicht von
             * Shared Mail erstellt wurden.
             */
            $bcc =
                trim(
                    (string)(
                        $headers->getValue(
                            'Bcc'
                        )
                        ?? ''
                    )
                );
        }

        /*
         * Subject direkt aus dem Header lesen.
         *
         * MailboxImapService verwendet für normale
         * Anzeige "(Kein Betreff)". Im Editor wollen
         * wir bei leerem Subject aber wirklich "".
         */
        $subjectValue =
            $headers->getValue(
                'Subject'
            );

        $subject =
            $subjectValue !== null
                ? trim(
                    (string)$subjectValue
                )
                : '';

        if (
            $subject === ''
            && isset($message['subject'])
            && $message['subject'] !== '(Kein Betreff)'
        ) {
            $subject =
                trim(
                    (string)$message['subject']
                );
        }

        $sourceFolder =
            $this->decodeDraftHeader(
                $headers,
                'X-SharedMail-Draft-Source-Folder'
            )
            ?? '';

        $sourceUid =
            (int)(
                $headers->getValue(
                    'X-SharedMail-Draft-Source-Uid'
                )
                ?? 0
            );

        if ($sourceUid < 0) {
            $sourceUid = 0;
        }

        return [
            'uid' =>
                $uid,

            'folder' =>
                $draftFolder,

            'version' =>
                $version,

            'kind' =>
                $kind,

            'to' =>
                $to,

            'cc' =>
                $cc,

            'bcc' =>
                $bcc,

            'subject' =>
                $subject,

            'sourceFolder' =>
                $sourceFolder,

            'sourceUid' =>
                $sourceUid,

            'body' => [
                'type' =>
                    (string)(
                        $message['body']['type']
                        ?? 'text'
                    ),

                'content' =>
                    (string)(
                        $message['body']['content']
                        ?? ''
                    ),
            ],

            'attachments' =>
                is_array(
                    $message['attachments']
                    ?? null
                )
                    ? $message['attachments']
                    : [],

            /*
             * Drafts, die von anderen Mailclients
             * stammen, können theoretisch im Drafts-
             * Ordner liegen, ohne dass unser eigener
             * X-Header existiert.
             *
             * Deshalb geben wir zusätzlich den
             * tatsächlichen IMAP-Flag zurück.
             */
            'draft' =>
                $isDraft,
        ];
    }

    /**
     * @return array{
     *     0: Horde_Mime_Headers,
     *     1: bool
     * }
     */
    private function loadHeaders(
        Mailbox $mailbox,
        string $folder,
        int $uid,
    ): array {
        $client =
            $this->createClient(
                $mailbox
            );

        try {
            $client->login();

            /*
             * false = UID, nicht Sequenznummer.
             */
            $ids =
                $client->getIdsOb(
                    $uid,
                    false
                );

            $query =
                new Horde_Imap_Client_Fetch_Query();

            /*
             * peek=true:
             * Keine globale \Seen-Änderung.
             */
            $query->headerText([
                'peek' =>
                    true,
            ]);

            $query->flags();
            $query->uid();

            $results =
                $client->fetch(
                    $folder,
                    $query,
                    [
                        'ids' =>
                            $ids,
                    ]
                );

            $message =
                $results->first();

            if (
                $message === null
                || $message === false
            ) {
                throw new RuntimeException(
                    'Der Entwurf wurde nicht gefunden.'
                );
            }

            $headers =
                $message->getHeaderText(
                    0,
                    Horde_Imap_Client_Data_Fetch::HEADER_PARSE
                );

            if (
                !$headers
                instanceof Horde_Mime_Headers
            ) {
                throw new RuntimeException(
                    'Die Header des Entwurfs konnten nicht gelesen werden.'
                );
            }

            $flags =
                array_map(
                    static fn (
                        mixed $flag
                    ): string =>
                        strtolower(
                            (string)$flag
                        ),
                    $message->getFlags()
                );

            return [
                $headers,

                in_array(
                    '\\draft',
                    $flags,
                    true
                ),
            ];
        } finally {
            try {
                $client->logout();
            } catch (Throwable) {
                // Verbindung wird ohnehin beendet.
            }
        }
    }

    private function decodeDraftHeader(
        Horde_Mime_Headers $headers,
        string $name,
    ): ?string {
        $encoded =
            $headers->getValue(
                $name
            );

        /*
         * null bedeutet:
         * Header existiert überhaupt nicht.
         *
         * Das ist wichtig für die Fallbacks.
         */
        if ($encoded === null) {
            return null;
        }

        $encoded =
            trim(
                (string)$encoded
            );

        if ($encoded === '') {
            return '';
        }

        /*
         * Base64url zurück in normales Base64.
         */
        $value =
            strtr(
                $encoded,
                '-_',
                '+/'
            );

        $remainder =
            strlen($value)
            % 4;

        if ($remainder !== 0) {
            $value .=
                str_repeat(
                    '=',
                    4 - $remainder
                );
        }

        $decoded =
            base64_decode(
                $value,
                true
            );

        if ($decoded === false) {
            /*
             * Kaputter Shared-Mail-Header darf nicht
             * dazu führen, dass der gesamte Draft
             * nicht mehr geöffnet werden kann.
             */
            return null;
        }

        return $decoded;
    }

    /**
     * @param mixed $addresses
     */
    private function formatAddresses(
        mixed $addresses,
    ): string {
        if (
            !is_array($addresses)
            && !$addresses instanceof \Traversable
        ) {
            return '';
        }

        $result = [];

        foreach ($addresses as $address) {
            if (!is_array($address)) {
                continue;
            }

            $name =
                trim(
                    (string)(
                        $address['name']
                        ?? ''
                    )
                );

            $email =
                trim(
                    (string)(
                        $address['email']
                        ?? ''
                    )
                );

            if (
                $name !== ''
                && $email !== ''
            ) {
                $result[] =
                    $name
                    . ' <'
                    . $email
                    . '>';

                continue;
            }

            if ($email !== '') {
                $result[] =
                    $email;

                continue;
            }

            if ($name !== '') {
                $result[] =
                    $name;
            }
        }

        return implode(
            ', ',
            $result
        );
    }

    private function findDraftFolder(
        Mailbox $mailbox,
    ): ?string {
        $folders =
            $this
                ->mailboxImapService
                ->getFolders(
                    $mailbox
                );

        /*
         * SPECIAL-USE hat Vorrang.
         */
        foreach ($folders as $folder) {
            if (
                strtolower(
                    (string)(
                        $folder['specialUse']
                        ?? ''
                    )
                ) === 'drafts'
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
            'Drafts',
            'Draft',
            'Entwürfe',
            'Entwuerfe',
            'INBOX/Drafts',
            'INBOX/Draft',
            'INBOX/Entwürfe',
            'INBOX/Entwuerfe',
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
            $this
                ->credentialService
                ->decrypt(
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

            'tls',
            'starttls' =>
                'tls',

            'none' =>
                false,

            default =>
                false,
        };
    }
}