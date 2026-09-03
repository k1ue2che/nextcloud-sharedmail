<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

use Horde_Mail_Transport_Smtphorde;
use Horde_Mime_Mail;
use InvalidArgumentException;
use OCA\SharedMail\Db\Mailbox;
use RuntimeException;

class ComposeSendService
{
    private const MAX_HTML_BYTES = 2_000_000;

    public function __construct(
        private readonly CredentialService $credentialService,
    ) {
    }

    /**
     * @return array{
     *     messageId: string,
     *     recipients: string[]
     * }
     */
    public function send(
        Mailbox $mailbox,
        string $to,
        string $cc,
        string $bcc,
        string $subject,
        string $html,
    ): array {
        $toRecipients =
            $this->parseRecipients(
                $to
            );

        $ccRecipients =
            $this->parseRecipients(
                $cc
            );

        $bccRecipients =
            $this->parseRecipients(
                $bcc
            );

        if ($toRecipients === []) {
            throw new InvalidArgumentException(
                'Bitte mindestens einen Empfänger angeben.'
            );
        }

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

        $subject =
            $this->sanitizeHeaderValue(
                $subject
            );

        $html =
            trim(
                $html
            );

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

        $smtpHost =
            trim(
                $mailbox->getSmtpHost()
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
                    $mailbox->getSmtpUsername(),

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

        $messageId =
            $this->generateMessageId(
                $mailbox
            );

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
         * Sichtbare Empfängerheader.
         */
        $mail->addHeader(
            'To',
            implode(
                ', ',
                $toRecipients
            )
        );

        if ($ccRecipients !== []) {
            $mail->addHeader(
                'Cc',
                implode(
                    ', ',
                    $ccRecipients
                )
            );
        }

        /*
         * BCC wird ausdrücklich NICHT als Header
         * in die Nachricht geschrieben.
         */

        $mail->setBody(
            $plainText
        );

        if (
            !method_exists(
                $mail,
                'setHtmlBody'
            )
        ) {
            throw new RuntimeException(
                'HTML-Mailversand wird von der installierten Horde-Version nicht unterstützt.'
            );
        }

        $mail->setHtmlBody(
            $html
        );

        /*
         * SMTP-Empfänger inklusive BCC.
         */
        foreach ($allRecipients as $recipient) {
            $mail->addRecipients(
                $recipient
            );
        }

        $mail->send(
            $transport
        );

        return [
            'messageId' =>
                $messageId,

            'recipients' =>
                $allRecipients,
        ];
    }

    /**
     * @return string[]
     */
    private function parseRecipients(
        string $value,
    ): array {
        $value =
            trim(
                $value
            );

        if ($value === '') {
            return [];
        }

        /*
         * Bis wir in 0.2.30 echte Kontakt-Chips
         * haben, akzeptieren wir Komma und Semikolon.
         */
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
                $this->extractEmailAddress(
                    $part
                );

            if (
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                throw new InvalidArgumentException(
                    'Ungültige E-Mail-Adresse: '
                    . $part
                );
            }

            $recipients[] =
                strtolower(
                    $email
                );
        }

        return array_values(
            array_unique(
                $recipients
            )
        );
    }

    private function extractEmailAddress(
        string $value,
    ): string {
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

        return trim(
            $value
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