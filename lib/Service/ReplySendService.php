<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

use OCP\IL10N;
use Horde_Mail_Transport_Smtphorde;
use Horde_Mime_Mail;
use Horde_Mime_Part;
use InvalidArgumentException;
use OCA\SharedMail\Db\Mailbox;
use RuntimeException;

class ReplySendService
{
    private const MAX_HTML_BYTES = 2_000_000;

    public function __construct(
        private readonly CredentialService $credentialService,
        private readonly ReplyContextService $replyContextService,
        private readonly SentMessageService $sentMessageService,
        private readonly IL10N $l,
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
     *     messageId: string,
     *     recipient: string,
     *     sentSaved: bool,
     *     sentFolder: string|null,
     *     answeredMarked: bool,
     *     warning: string|null
     * }
     */
    public function sendReply(
        Mailbox $mailbox,
        string $folder,
        int $uid,
        string $to,
        string $subject,
        string $html,
        array $attachments = [],
    ): array {
        $folder =
            trim(
                $folder
            );

        if ($folder === '') {
            $folder =
                'INBOX';
        }

        if ($uid <= 0) {
            throw new InvalidArgumentException(
                $this->l->t('Invalid message UID.')
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
                $this->l->t('The recipient address is invalid.')
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
            trim(
                $html
            );

        if ($html === '') {
            throw new InvalidArgumentException(
                $this->l->t('The message must not be empty.')
            );
        }

        if (
            strlen($html)
            > self::MAX_HTML_BYTES
        ) {
            throw new InvalidArgumentException(
                $this->l->t('The message is too large.')
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
                $this->l->t('The message contains no text.')
            );
        }

        /*
         * Threading-Kontext.
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

        $smtpHost =
            trim(
                $mailbox->getSmtpHost()
            );

        if ($smtpHost === '') {
            throw new RuntimeException(
                $this->l->t('No SMTP server is configured for this mailbox.')
            );
        }

        $smtpPassword =
            $this
                ->credentialService
                ->decrypt(
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

        $mail =
            new Horde_Mime_Mail();

        $mail->addHeader(
            'Date',
            date('r')
        );

        $mail->addHeader(
            'Message-ID',
            $newMessageId
        );

        $mail->addHeader(
            'From',
            $mailbox->getEmail()
        );

        $mail->addHeader(
            'Subject',
            $subject
        );

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
                $this->l->t('The installed Horde MIME version does not support sending HTML mail.')
            );
        }

        $mail->setHtmlBody(
            $html
        );

        /*
         * Optionale Anhänge.
         */
        $this->addAttachments(
            $mail,
            $attachments
        );

        $mail->addRecipients(
            $recipient
        );

        /*
         * SMTP.
         */
        $mail->send(
            $transport
        );

        /*
         * Exakt gesendete MIME-Mail für Sent.
         */
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

        $sentResult =
            $this
                ->sentMessageService
                ->appendToSent(
                    $mailbox,
                    $rawMessage
                );

        /*
         * Original als beantwortet markieren.
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
                $this->l->t('The original message could not be marked as answered.');
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
        if ($attachments === []) {
            return;
        }

        if (
            !method_exists(
                $mail,
                'addMimePart'
            )
        ) {
            throw new RuntimeException(
                $this->l->t('The installed Horde MIME version does not support attachments.')
            );
        }

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
                    $this->l->t('An attachment does not have a valid file name.')
                );
            }

            if (!is_string($content)) {
                throw new InvalidArgumentException(
                    $this->l->t('An attachment does not contain valid file data.')
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

    private function extractEmailAddress(
        string $value,
    ): string {
        $value =
            trim(
                $value
            );

        if ($value === '') {
            return '';
        }

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
            trim(
                $value
            );

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