<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

use Horde_Mail_Transport_Smtphorde;
use Horde_Mime_Mail;
use OCA\SharedMail\Db\Mailbox;
use InvalidArgumentException;
use RuntimeException;

class ReplySendService
{
    private const MAX_HTML_BYTES =
        2_000_000;

    public function __construct(
        private readonly CredentialService $credentialService,
        private readonly ReplyContextService $replyContextService,
        private readonly SentMessageService $sentMessageService,
    ) {
    }

    /**
     * @return array{
     *     messageId: string,
     *     recipient: string
     * }
     */
    public function sendReply(
        Mailbox $mailbox,
        string $folder,
        int $uid,
        string $to,
        string $subject,
        string $html,
    ): array {
        $folder =
            trim($folder);

        if ($folder === '') {
            $folder =
                'INBOX';
        }

        if ($uid <= 0) {
            throw new InvalidArgumentException(
                'Ungültige Nachrichten-UID.'
            );
        }

        $recipient =
            $this->extractEmailAddress(
                $to
            );

        if (
            !filter_var(
                $recipient,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            throw new InvalidArgumentException(
                'Die Empfängeradresse ist ungültig.'
            );
        }

        $subject =
            $this->sanitizeHeaderValue(
                $subject
            );

        if ($subject === '') {
            $subject =
                'Re:';
        }

        $html =
            trim($html);

        if ($html === '') {
            throw new InvalidArgumentException(
                'Die Nachricht darf nicht leer sein.'
            );
        }

        if (
            strlen($html)
            > self::MAX_HTML_BYTES
        ) {
            throw new InvalidArgumentException(
                'Die Nachricht ist zu groß.'
            );
        }

        /*
         * CKEditor liefert kontrolliertes HTML.
         *
         * Trotzdem entfernen wir serverseitig Dinge,
         * die in einer E-Mail nichts verloren haben.
         */
        $html =
            $this->sanitizeHtml(
                $html
            );

        $plainText =
            $this->htmlToPlainText(
                $html
            );

        if (
            trim($plainText)
            === ''
        ) {
            throw new InvalidArgumentException(
                'Die Nachricht enthält keinen Text.'
            );
        }

        /*
         * Originale Thread-Informationen holen.
         */
        $context =
            $this
                ->replyContextService
                ->getContext(
                    $mailbox,
                    $folder,
                    $uid
                );

        $originalMessageId =
            $this->normalizeMessageId(
                $context['messageId']
            );

        $references =
            $this->buildReferences(
                $context['references'],
                $originalMessageId
            );

        $newMessageId =
            $this->generateMessageId(
                $mailbox
            );

        /*
         * SMTP-Zugangsdaten.
         */
        $smtpHost =
            trim(
                $mailbox->getSmtpHost()
            );

        $smtpUsername =
            trim(
                $mailbox->getSmtpUsername()
            );

        if ($smtpHost === '') {
            throw new RuntimeException(
                'Für dieses Postfach ist kein SMTP-Server konfiguriert.'
            );
        }

        $smtpPassword =
            $this->credentialService->decrypt(
                (string)$mailbox->getSmtpPassword()
            );

        /*
         * Transport wie beim bereits funktionierenden
         * SMTP-Verbindungstest.
         */
        $transport =
            new Horde_Mail_Transport_Smtphorde([
                'host' =>
                    $smtpHost,

                'port' =>
                    $mailbox->getSmtpPort(),

                'secure' =>
                    $this->normalizeSecurity(
                        $mailbox->getSmtpSecurity()
                    ),

                'username' =>
                    $smtpUsername,

                'password' =>
                    $smtpPassword,

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

        $mail =
            new Horde_Mime_Mail();

        /*
         * Standardheader.
         */
        $mail->addHeader(
            'Date',
            date('r')
        );

        $mail->addHeader(
            'Message-ID',
            $newMessageId
        );

        /*
         * Als Absender wird ausdrücklich das
         * gemeinsame Postfach verwendet.
         */
        $mail->addHeader(
            'From',
            $mailbox->getEmail()
        );

        $mail->addHeader(
            'Subject',
            $subject
        );

        /*
         * Threading.
         */
        if ($originalMessageId !== '') {
            $mail->addHeader(
                'In-Reply-To',
                $originalMessageId
            );
        }

        if ($references !== '') {
            $mail->addHeader(
                'References',
                $references
            );
        }

        /*
         * Plaintext-Fallback.
         */
        $mail->setBody(
            $plainText
        );

        /*
         * Horde_Mime_Mail unterstützt HTML.
         *
         * Wir prüfen method_exists trotzdem, damit ein
         * zukünftiges Library-Problem einen verständlichen
         * Fehler statt eines PHP-Fatal-Errors erzeugt.
         */
        if (
            !method_exists(
                $mail,
                'setHtmlBody'
            )
        ) {
            throw new RuntimeException(
                'Die installierte Horde-MIME-Version unterstützt keinen HTML-Mailversand.'
            );
        }

        $mail->setHtmlBody(
            $html
        );

        /*
         * addRecipients kümmert sich um den eigentlichen
         * SMTP-Empfänger.
         */
        $mail->addRecipients(
            $recipient
        );

        $rawMessage =
            $mail->getRaw();

        if (is_resource($rawMessage)) {
            $rawMessage =
                stream_get_contents(
                    $rawMessage
                );
        }

        $rawMessage =
            (string)$rawMessage;

        /*
         * Jetzt wird tatsächlich gesendet.
         */
        $mail->send(
            $transport
        );

        /*
        * Gesendete Antwort im IMAP-Sent ablegen.
        */
        $sentResult =
            $this
                ->sentMessageService
                ->appendToSent(
                    $mailbox,
                    $rawMessage
                );


        /*
        * Originalmail als beantwortet markieren.
        */
        $answeredMarked =
            $this
                ->sentMessageService
                ->markAnswered(
                    $mailbox,
                    $folder,
                    $uid
                );
                
        $warnings = [];

        if (!$sentResult['success']) {
            $warnings[] =
                $sentResult['message'];
        }

        if (!$answeredMarked) {
            $warnings[] =
                'Die Originalmail konnte nicht als beantwortet markiert werden.';
        }

        return [
            'messageId' =>
                $newMessageId,

            'recipient' =>
                $recipient,

            'sentSaved' =>
                $sentResult['success'],

            'sentFolder' =>
                $sentResult['folder'],

            'answeredMarked' =>
                $answeredMarked,

            'warning' =>
                $warnings !== []
                    ? implode(
                        ' ',
                        $warnings
                    )
                    : null,
        ];
    }

    private function extractEmailAddress(
        string $value,
    ): string {
        $value =
            trim($value);

        if ($value === '') {
            return '';
        }

        /*
         * Beispiel:
         *
         * Christine Hunger <christine@example.de>
         */
        if (
            preg_match(
                '/<([^<>]+)>/',
                $value,
                $matches
            ) === 1
        ) {
            return trim(
                $matches[1]
            );
        }

        return $value;
    }

    private function sanitizeHeaderValue(
        string $value,
    ): string {
        /*
         * Header-Injection verhindern.
         */
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
        /*
         * Die eigentliche Nachricht ist kein vollständiges
         * HTML-Dokument, sondern das CKEditor-Fragment.
         *
         * Skripte, Styles, Iframes und ähnliche Elemente
         * werden niemals versendet.
         */
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

        /*
         * JavaScript-Eventhandler entfernen.
         */
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

        /*
         * javascript:-Links entfernen.
         */
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
        /*
         * Zeilenstruktur vor strip_tags erhalten.
         */
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

        $text =
            preg_replace(
                "/[ \t]+\n/u",
                "\n",
                $text
            ) ?? $text;

        $text =
            preg_replace(
                "/\n{4,}/u",
                "\n\n\n",
                $text
            ) ?? $text;

        return trim(
            $text
        );
    }

    private function normalizeMessageId(
        string $value,
    ): string {
        $value =
            trim($value);

        if ($value === '') {
            return '';
        }

        if (
            preg_match(
                '/<[^<>\r\n]+>/',
                $value,
                $matches
            ) === 1
        ) {
            return $matches[0];
        }

        return '';
    }

    private function buildReferences(
        string $existingReferences,
        string $originalMessageId,
    ): string {
        $ids = [];

        if (
            preg_match_all(
                '/<[^<>\r\n]+>/',
                $existingReferences,
                $matches
            ) > 0
        ) {
            foreach ($matches[0] as $id) {
                $ids[] =
                    $id;
            }
        }

        if (
            $originalMessageId !== ''
            && !in_array(
                $originalMessageId,
                $ids,
                true
            )
        ) {
            $ids[] =
                $originalMessageId;
        }

        /*
         * References kann theoretisch sehr lang werden.
         * Für unseren Client reichen die letzten 20 IDs.
         */
        if (
            count($ids)
            > 20
        ) {
            $ids =
                array_slice(
                    $ids,
                    -20
                );
        }

        return implode(
            ' ',
            $ids
        );
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

        $atPosition =
            strrpos(
                $email,
                '@'
            );

        if (
            $atPosition !== false
            && $atPosition
                < strlen($email) - 1
        ) {
            $domain =
                substr(
                    $email,
                    $atPosition + 1
                );
        }

        $domain =
            preg_replace(
                '/[^A-Za-z0-9.-]/',
                '',
                $domain
            ) ?: 'localhost';

        return sprintf(
            '<sharedmail.%d.%s@%s>',
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