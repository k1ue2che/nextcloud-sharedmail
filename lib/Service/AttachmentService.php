<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

use OCA\SharedMail\Db\Mailbox;
use RuntimeException;
use Throwable;

class AttachmentService
{
    public function __construct(
        private readonly CredentialService $credentialService,
    ) {
    }

    /**
     * @return array{
     *     content:string,
     *     name:string,
     *     contentType:string,
     *     size:int
     * }
     */
    public function getAttachment(
        Mailbox $mailbox,
        string $folder,
        int $uid,
        string $mimeId,
    ): array {
        $folder = trim($folder);
        $mimeId = trim($mimeId);

        if ($folder === '') {
            throw new RuntimeException(
                'Der IMAP-Ordner fehlt.'
            );
        }

        if ($uid <= 0) {
            throw new RuntimeException(
                'Ungültige Nachrichten-ID.'
            );
        }

        if ($mimeId === '') {
            throw new RuntimeException(
                'Die MIME-ID des Anhangs fehlt.'
            );
        }

        /*
         * Nur normale MIME-Part-IDs zulassen:
         *
         * 1
         * 2
         * 2.1
         * 3.2.1
         */
        if (
            !preg_match(
                '/^[0-9]+(?:\.[0-9]+)*$/',
                $mimeId
            )
        ) {
            throw new RuntimeException(
                'Ungültige MIME-ID.'
            );
        }

        $client =
            $this->createClient(
                $mailbox
            );

        try {
            $client->login();

            $ids =
                $client->getIdsOb(
                    [$uid],
                    false
                );

            /*
             * Zuerst MIME-Struktur laden.
             */
            $structureQuery =
                new \Horde_Imap_Client_Fetch_Query();

            $structureQuery->structure();

            $structureResults =
                $client->fetch(
                    $folder,
                    $structureQuery,
                    [
                        'ids' => $ids,
                    ]
                );

            $structureMessage =
                $structureResults->first();

            if (
                $structureMessage === null
                || $structureMessage === false
            ) {
                throw new RuntimeException(
                    'Die Nachricht wurde nicht gefunden.'
                );
            }

            $structure =
                $structureMessage->getStructure();

            if (
                !$structure
                instanceof \Horde_Mime_Part
            ) {
                throw new RuntimeException(
                    'Die MIME-Struktur der Nachricht konnte nicht gelesen werden.'
                );
            }

            $part =
                $structure->getPart(
                    $mimeId
                );

            if (
                !$part
                instanceof \Horde_Mime_Part
            ) {
                throw new RuntimeException(
                    'Der Anhang wurde nicht gefunden.'
                );
            }

            /*
             * Multipart-Knoten dürfen niemals
             * heruntergeladen werden.
             */
            if (
                strtolower(
                    (string)$part->getPrimaryType()
                ) === 'multipart'
            ) {
                throw new RuntimeException(
                    'Dieser MIME-Part ist keine Datei.'
                );
            }

            $disposition =
                strtolower(
                    trim(
                        (string)$part->getDisposition()
                    )
                );

            $name =
                trim(
                    (string)$part->getName(true)
                );

            $contentId =
                $part->getContentId();

            /*
             * Nur Parts ausliefern, die auch
             * plausibel ein Anhang / Inline-Datei sind.
             *
             * Dadurch kann man nicht einfach über
             * eine erratene MIME-ID den Mailbody
             * über den Download-Endpunkt abrufen.
             */
            $isAttachment =
                $disposition === 'attachment'
                || $disposition === 'inline'
                || $name !== ''
                || $contentId !== null;

            if (!$isAttachment) {
                throw new RuntimeException(
                    'Dieser MIME-Part ist kein Anhang.'
                );
            }

            if ($name === '') {
                $name =
                    $contentId !== null
                        ? 'Inline-Datei'
                        : 'Anhang';
            }

            $contentType =
                trim(
                    (string)$part->getType()
                );

            if ($contentType === '') {
                $contentType =
                    'application/octet-stream';
            }

            /*
             * Jetzt exakt denselben Fetch-Mechanismus
             * wie beim bereits funktionierenden
             * Mailbody verwenden.
             */
            $attachmentQuery =
                new \Horde_Imap_Client_Fetch_Query();

            $attachmentQuery->bodyPart(
                $mimeId,
                [
                    'decode' => true,

                    /*
                     * Download darf die Mail global
                     * NICHT als gelesen markieren.
                     */
                    'peek' => true,
                ]
            );

            $attachmentResults =
                $client->fetch(
                    $folder,
                    $attachmentQuery,
                    [
                        'ids' => $ids,
                    ]
                );

            $attachmentMessage =
                $attachmentResults->first();

            if (
                $attachmentMessage === null
                || $attachmentMessage === false
            ) {
                throw new RuntimeException(
                    'Der Anhang konnte nicht geladen werden.'
                );
            }

            $content =
                $attachmentMessage
                    ->getBodyPart(
                        $mimeId
                    );

            if ($content === null) {
                throw new RuntimeException(
                    'Der Anhang enthält keine Daten.'
                );
            }

            $content =
                (string)$content;

            /*
             * Manche IMAP-Server führen decode=true
             * nicht selbst aus.
             *
             * Dann übernimmt Horde Mime lokal die
             * Transfer-Decodierung.
             */
            if (
                !$attachmentMessage
                    ->getBodyPartDecode(
                        $mimeId
                    )
            ) {
                $partCopy =
                    clone $part;

                $partCopy->setContents(
                    $content
                );

                $content =
                    (string)$partCopy
                        ->getContents();
            }

            /*
             * Header-Injection über Dateinamen
             * vorsorglich verhindern.
             */
            $name =
                str_replace(
                    [
                        "\r",
                        "\n",
                        "\0",
                    ],
                    '',
                    $name
                );

            if ($name === '') {
                $name = 'Anhang';
            }

            return [
                'content' =>
                    $content,

                'name' =>
                    $name,

                'contentType' =>
                    $contentType,

                'size' =>
                    strlen($content),
            ];
        } finally {
            try {
                $client->logout();
            } catch (Throwable) {
                // Verbindung wird ohnehin verworfen.
            }
        }
    }


    private function createClient(
        Mailbox $mailbox
    ): \Horde_Imap_Client_Socket {
        $security =
            strtolower(
                trim(
                    (string)
                    $mailbox->getImapSecurity()
                )
            );

        $secure =
            match ($security) {
                'ssl' =>
                    'ssl',

                'tls',
                'starttls' =>
                    'tls',

                default =>
                    false,
            };

        $password =
            $this
                ->credentialService
                ->decrypt(
                    (string)
                    $mailbox->getImapPassword()
                );

        return new \Horde_Imap_Client_Socket(
            [
                'username' =>
                    (string)$mailbox->getImapUsername(),

                'password' =>
                    $password,

                'hostspec' =>
                    (string)$mailbox->getImapHost(),

                'port' =>
                    (int)$mailbox->getImapPort(),

                'secure' =>
                    $secure,

                'timeout' =>
                    30,

                'context' => [
                    'ssl' => [
                        'verify_peer' =>
                            true,

                        'verify_peer_name' =>
                            true,

                        'allow_self_signed' =>
                            false,
                    ],
                ],
            ]
        );
    }
}