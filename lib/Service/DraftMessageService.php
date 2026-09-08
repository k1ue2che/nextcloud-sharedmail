<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

use Horde_Imap_Client_Socket;
use Horde_Mail_Transport_Null;
use Horde_Mime_Mail;
use Horde_Mime_Part;
use InvalidArgumentException;
use OCA\SharedMail\Db\Mailbox;
use RuntimeException;
use Throwable;

class DraftMessageService
{
    private const MAX_HTML_BYTES = 2_000_000;

    public function __construct(
        private readonly CredentialService $credentialService,
        private readonly MailboxImapService $mailboxImapService,
    ) {
    }

    /**
     * @param array<int, array{
     *     name: string,
     *     type: string,
     *     size: int,
     *     content: string
     * }> $attachments
     *
     * @return array{
     *     folder: string,
     *     uid: int|null,
     *     messageId: string,
     *     replaced: bool,
     *     warning: string|null
     * }
     */
    public function saveDraft(
        Mailbox $mailbox,
        string $to,
        string $cc,
        string $bcc,
        string $subject,
        string $html,
        array $attachments = [],
        string $sourceFolder = '',
        int $sourceUid = 0,
        int $replaceDraftUid = 0,
    ): array {
        $to =
            $this->sanitizeDraftField(
                $to
            );

        $cc =
            $this->sanitizeDraftField(
                $cc
            );

        $bcc =
            $this->sanitizeDraftField(
                $bcc
            );

        $subject =
            $this->sanitizeHeaderValue(
                $subject
            );

        $sourceFolder =
            trim(
                $sourceFolder
            );

        if ($sourceUid < 0) {
            $sourceUid = 0;
        }

        if ($replaceDraftUid < 0) {
            $replaceDraftUid = 0;
        }

        $html =
            trim(
                $html
            );

        if (
            strlen($html)
            > self::MAX_HTML_BYTES
        ) {
            throw new InvalidArgumentException(
                'Der Nachrichtentext ist zu groß.'
            );
        }

        if ($html !== '') {
            $html =
                $this->sanitizeHtml(
                    $html
                );
        }

        /*
         * Vollständig leere Drafts werden nicht angelegt.
         */
        if (
            $to === ''
            && $cc === ''
            && $bcc === ''
            && $subject === ''
            && $html === ''
            && $attachments === []
        ) {
            throw new InvalidArgumentException(
                'Ein vollständig leerer Entwurf wird nicht gespeichert.'
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

        $messageId =
            $this->generateMessageId(
                $mailbox
            );

        $rawMessage =
            $this->buildRawDraft(
                $mailbox,
                $messageId,
                $to,
                $cc,
                $bcc,
                $subject,
                $html,
                $attachments,
                $sourceFolder,
                $sourceUid
            );

        $client =
            $this->createClient(
                $mailbox
            );

        try {
            $client->login();

            /*
             * Zuerst neue Draft-Version speichern.
             */
            $appendedIds =
                $client->append(
                    $draftFolder,
                    [
                        [
                            'data' =>
                                $rawMessage,

                            'flags' => [
                                '\\Draft',
                                '\\Seen',
                            ],
                        ],
                    ]
                );

            $newDraftUid =
                $this->extractFirstUid(
                    $appendedIds
                );

            $replaced =
                false;

            $warning =
                null;

            /*
             * Existierenden Draft ersetzen.
             */
            if ($replaceDraftUid > 0) {
                if ($newDraftUid === null) {
                    $warning =
                        'Der neue Entwurf wurde gespeichert, die alte Version konnte aber nicht sicher ersetzt werden.';
                } elseif (
                    $newDraftUid
                    !== $replaceDraftUid
                ) {
                    try {
                        $this->deleteDraftByUid(
                            $client,
                            $draftFolder,
                            $replaceDraftUid
                        );

                        $replaced =
                            true;
                    } catch (Throwable) {
                        /*
                         * Neuer Draft existiert bereits.
                         * Deshalb Save nicht als Fehler melden.
                         */
                        $warning =
                            'Der neue Entwurf wurde gespeichert, die vorherige Version konnte aber nicht entfernt werden.';
                    }
                }
            }

            return [
                'folder' =>
                    $draftFolder,

                'uid' =>
                    $newDraftUid,

                'messageId' =>
                    $messageId,

                'replaced' =>
                    $replaced,

                'warning' =>
                    $warning,
            ];
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable) {
            throw new RuntimeException(
                'Der Entwurf konnte nicht im IMAP-Postfach gespeichert werden.'
            );
        } finally {
            try {
                $client->logout();
            } catch (Throwable) {
                // Verbindung wird ohnehin beendet.
            }
        }
    }

    /**
     * Löscht einen gespeicherten Draft nach
     * erfolgreichem Versand.
     *
     * WICHTIG:
     *
     * Diese Methode wirft absichtlich keine Exception
     * nach außen. Wenn SMTP bereits erfolgreich war,
     * darf ein Fehler beim Aufräumen des Drafts nicht
     * dazu führen, dass der Versand als fehlgeschlagen
     * gemeldet wird.
     *
     * @return array{
     *     success: bool,
     *     folder: string|null,
     *     message: string|null
     * }
     */
    public function deleteDraft(
        Mailbox $mailbox,
        int $uid,
    ): array {
        if ($uid <= 0) {
            return [
                'success' =>
                    false,

                'folder' =>
                    null,

                'message' =>
                    'Ungültige Entwurfs-ID.',
            ];
        }

        $client =
            null;

        $draftFolder =
            null;

        try {
            /*
             * Drafts-Ordner ausschließlich serverseitig
             * bestimmen.
             *
             * Der Browser darf keinen beliebigen
             * IMAP-Ordner zum Löschen angeben.
             */
            $draftFolder =
                $this->findDraftFolder(
                    $mailbox
                );

            if ($draftFolder === null) {
                return [
                    'success' =>
                        false,

                    'folder' =>
                        null,

                    'message' =>
                        'Die Nachricht wurde gesendet, der Entwurfsordner konnte aber nicht gefunden werden.',
                ];
            }

            $client =
                $this->createClient(
                    $mailbox
                );

            $client->login();

            /*
             * Dieselbe bereits funktionierende
             * Löschroutine verwenden, die auch beim
             * Ersetzen eines Drafts benutzt wird.
             */
            $this->deleteDraftByUid(
                $client,
                $draftFolder,
                $uid
            );

            return [
                'success' =>
                    true,

                'folder' =>
                    $draftFolder,

                'message' =>
                    null,
            ];
        } catch (Throwable) {
            /*
             * SMTP kann bereits erfolgreich gewesen sein.
             *
             * Deshalb niemals Exception weiterwerfen.
             */
            return [
                'success' =>
                    false,

                'folder' =>
                    $draftFolder,

                'message' =>
                    'Die Nachricht wurde gesendet, der Entwurf konnte aber nicht entfernt werden.',
            ];
        } finally {
            if (
                $client
                instanceof Horde_Imap_Client_Socket
            ) {
                try {
                    $client->logout();
                } catch (Throwable) {
                    // Verbindung wird ohnehin beendet.
                }
            }
        }
    }

    /**
     * Löscht genau eine Draft-UID.
     */
    private function deleteDraftByUid(
        Horde_Imap_Client_Socket $client,
        string $draftFolder,
        int $uid,
    ): void {
        if ($uid <= 0) {
            return;
        }

        $ids =
            $client->getIdsOb(
                $uid,
                false
            );

        $client->expunge(
            $draftFolder,
            [
                'ids' =>
                    $ids,

                'delete' =>
                    true,
            ]
        );
    }

    private function extractFirstUid(
        mixed $ids,
    ): ?int {
        if (!is_iterable($ids)) {
            return null;
        }

        foreach ($ids as $id) {
            $uid =
                (int)$id;

            if ($uid > 0) {
                return $uid;
            }
        }

        return null;
    }

    /**
     * @param array<int, array{
     *     name: string,
     *     type: string,
     *     size: int,
     *     content: string
     * }> $attachments
     */
    private function buildRawDraft(
        Mailbox $mailbox,
        string $messageId,
        string $to,
        string $cc,
        string $bcc,
        string $subject,
        string $html,
        array $attachments,
        string $sourceFolder,
        int $sourceUid,
    ): string {
        $mail =
            new Horde_Mime_Mail();

        $mail->addHeader(
            'Date',
            date('r')
        );

        $mail->addHeader(
            'Message-ID',
            $messageId
        );

        $mail->addHeader(
            'From',
            $mailbox->getEmail()
        );

        if ($subject !== '') {
            $mail->addHeader(
                'Subject',
                $subject
            );
        }

        /*
         * Normale RFC-Header enthalten nur
         * bereits gültige Mailadressen.
         */
        $toRecipients =
            $this->extractValidRecipients(
                $to
            );

        $ccRecipients =
            $this->extractValidRecipients(
                $cc
            );

        $bccRecipients =
            $this->extractValidRecipients(
                $bcc
            );

        if ($toRecipients !== []) {
            $mail->addHeader(
                'To',
                implode(
                    ', ',
                    $toRecipients
                )
            );
        }

        if ($ccRecipients !== []) {
            $mail->addHeader(
                'Cc',
                implode(
                    ', ',
                    $ccRecipients
                )
            );
        }

        if ($bccRecipients !== []) {
            $mail->addHeader(
                'Bcc',
                implode(
                    ', ',
                    $bccRecipients
                )
            );
        }

        /*
         * Shared-Mail-Draft-Metadaten.
         */
        $mail->addHeader(
            'X-SharedMail-Draft-Version',
            '1'
        );

        $mail->addHeader(
            'X-SharedMail-Draft-Kind',
            (
                $sourceUid > 0
                && $sourceFolder !== ''
            )
                ? 'reply'
                : 'compose'
        );

        if ($to !== '') {
            $mail->addHeader(
                'X-SharedMail-Draft-To',
                $this->encodeDraftValue(
                    $to
                )
            );
        }

        if ($cc !== '') {
            $mail->addHeader(
                'X-SharedMail-Draft-Cc',
                $this->encodeDraftValue(
                    $cc
                )
            );
        }

        if ($bcc !== '') {
            $mail->addHeader(
                'X-SharedMail-Draft-Bcc',
                $this->encodeDraftValue(
                    $bcc
                )
            );
        }

        if ($sourceFolder !== '') {
            $mail->addHeader(
                'X-SharedMail-Draft-Source-Folder',
                $this->encodeDraftValue(
                    $sourceFolder
                )
            );
        }

        if ($sourceUid > 0) {
            $mail->addHeader(
                'X-SharedMail-Draft-Source-Uid',
                (string)$sourceUid
            );
        }

        /*
         * Body.
         */
        $plainText =
            $html !== ''
                ? $this->htmlToPlainText(
                    $html
                )
                : '';

        $mail->setBody(
            $plainText
        );

        if ($html !== '') {
            if (
                !method_exists(
                    $mail,
                    'setHtmlBody'
                )
            ) {
                throw new RuntimeException(
                    'Die installierte Horde-MIME-Version unterstützt keine HTML-Entwürfe.'
                );
            }

            $mail->setHtmlBody(
                $html
            );
        }

        /*
         * Anhänge.
         */
        $this->addAttachments(
            $mail,
            $attachments
        );

        /*
         * Gültige Empfänger registrieren.
         */
        $allRecipients =
            array_values(
                array_unique(
                    array_merge(
                        $toRecipients,
                        $ccRecipients,
                        $bccRecipients
                    )
                )
            );

        foreach ($allRecipients as $recipient) {
            $mail->addRecipients(
                $recipient
            );
        }

        /*
         * MIME-Baum ohne SMTP-Versand aufbauen.
         */
        $mail->send(
            new Horde_Mail_Transport_Null(),
            true
        );

        /*
         * BCC muss im Draft erhalten bleiben.
         */
        if (
            method_exists(
                $mail,
                'removeHeader'
            )
        ) {
            $mail->removeHeader(
                'Bcc'
            );
        }

        if ($bccRecipients !== []) {
            $mail->addHeader(
                'Bcc',
                implode(
                    ', ',
                    $bccRecipients
                )
            );
        }

        $rawMessage =
            $mail->getRaw();

        if (is_resource($rawMessage)) {
            $rawMessage =
                stream_get_contents(
                    $rawMessage
                );
        }

        if (
            !is_string($rawMessage)
            || $rawMessage === ''
        ) {
            throw new RuntimeException(
                'Der Entwurf konnte nicht als MIME-Nachricht erzeugt werden.'
            );
        }

        return $rawMessage;
    }

    /**
     * @param array<int, array{
     *     name: string,
     *     type: string,
     *     size: int,
     *     content: string
     * }> $attachments
     */
    private function addAttachments(
        Horde_Mime_Mail $mail,
        array $attachments,
    ): void {
        foreach ($attachments as $attachment) {
            $name =
                trim(
                    (string)(
                        $attachment['name']
                        ?? ''
                    )
                );

            $type =
                strtolower(
                    trim(
                        (string)(
                            $attachment['type']
                            ?? ''
                        )
                    )
                );

            $content =
                $attachment['content']
                ?? null;

            if ($name === '') {
                throw new InvalidArgumentException(
                    'Ein Anhang besitzt keinen gültigen Dateinamen.'
                );
            }

            if (!is_string($content)) {
                throw new InvalidArgumentException(
                    'Ein Anhang enthält keine gültigen Dateidaten.'
                );
            }

            if (
                preg_match(
                    '#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i',
                    $type
                ) !== 1
            ) {
                $type =
                    'application/octet-stream';
            }

            $part =
                new Horde_Mime_Part();

            $part->setType(
                $type
            );

            $part->setContents(
                $content
            );

            $part->setName(
                $name
            );

            $part->setDisposition(
                'attachment'
            );

            $part->setTransferEncoding(
                'base64',
                [
                    'send' =>
                        true,
                ]
            );

            $mail->addMimePart(
                $part
            );
        }
    }

    /**
     * @return string[]
     */
    private function extractValidRecipients(
        string $value,
    ): array {
        $value =
            trim(
                $value
            );

        if ($value === '') {
            return [];
        }

        $parts =
            preg_split(
                '/[;,]+/',
                $value
            ) ?: [];

        $recipients = [];

        foreach ($parts as $part) {
            $part =
                trim(
                    $part
                );

            if ($part === '') {
                continue;
            }

            $email =
                $part;

            if (
                preg_match(
                    '/<([^<>]+)>/',
                    $part,
                    $matches
                ) === 1
            ) {
                $email =
                    trim(
                        $matches[1]
                    );
            }

            if (
                filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                $recipients[] =
                    strtolower(
                        $email
                    );
            }
        }

        return array_values(
            array_unique(
                $recipients
            )
        );
    }

    private function sanitizeDraftField(
        string $value,
    ): string {
        return trim(
            str_replace(
                [
                    "\r",
                    "\n",
                    "\0",
                ],
                ' ',
                $value
            )
        );
    }

    private function sanitizeHeaderValue(
        string $value,
    ): string {
        $value =
            str_replace(
                [
                    "\r",
                    "\n",
                    "\0",
                ],
                ' ',
                $value
            );

        return trim(
            preg_replace(
                '/\s+/u',
                ' ',
                $value
            ) ?? $value
        );
    }

    private function sanitizeHtml(
        string $html,
    ): string {
        $html =
            preg_replace(
                '#<(script|style|iframe|object|embed|form|input|button|textarea|select)\b[^>]*>.*?</\1>#is',
                '',
                $html
            ) ?? $html;

        $html =
            preg_replace(
                '#<(script|style|iframe|object|embed|form|input|button|textarea|select)\b[^>]*/?>#is',
                '',
                $html
            ) ?? $html;

        $html =
            preg_replace(
                '/\s+on[a-z]+\s*=\s*(["\']).*?\1/isu',
                '',
                $html
            ) ?? $html;

        $html =
            preg_replace(
                '/\s+on[a-z]+\s*=\s*[^\s>]+/isu',
                '',
                $html
            ) ?? $html;

        $html =
            preg_replace(
                '/href\s*=\s*(["\'])\s*javascript:[^"\']*\1/isu',
                'href="#"',
                $html
            ) ?? $html;

        return trim(
            $html
        );
    }

    private function htmlToPlainText(
        string $html,
    ): string {
        $text =
            preg_replace(
                '#<br\s*/?>#i',
                "\n",
                $html
            ) ?? $html;

        $text =
            preg_replace(
                '#</p\s*>#i',
                "\n\n",
                $text
            ) ?? $text;

        $text =
            preg_replace(
                '#</div\s*>#i',
                "\n",
                $text
            ) ?? $text;

        $text =
            preg_replace(
                '#</li\s*>#i',
                "\n",
                $text
            ) ?? $text;

        $text =
            strip_tags(
                $text
            );

        $text =
            html_entity_decode(
                $text,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

        $text =
            str_replace(
                [
                    "\r\n",
                    "\r",
                ],
                "\n",
                $text
            );

        return trim(
            $text
        );
    }

    private function encodeDraftValue(
        string $value,
    ): string {
        return rtrim(
            strtr(
                base64_encode(
                    $value
                ),
                '+/',
                '-_'
            ),
            '='
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

    private function generateMessageId(
        Mailbox $mailbox,
    ): string {
        $email =
            trim(
                $mailbox->getEmail()
            );

        $domain =
            'localhost';

        $position =
            strrpos(
                $email,
                '@'
            );

        if (
            $position !== false
            && $position
                < strlen($email) - 1
        ) {
            $domain =
                substr(
                    $email,
                    $position + 1
                );
        }

        $domain =
            preg_replace(
                '/[^A-Za-z0-9.-]/',
                '',
                $domain
            ) ?: 'localhost';

        return sprintf(
            '<sharedmail.draft.%d.%s@%s>',
            time(),
            bin2hex(
                random_bytes(12)
            ),
            $domain
        );
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