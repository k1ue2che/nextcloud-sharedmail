<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

use Horde_Imap_Client;
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
            /*
             * Login bewusst direkt erzwingen.
             */
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

                /*
                 * STATUS nur für echte auswählbare Ordner abfragen.
                 */
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
                        /*
                         * Wenn ein einzelner Ordner keinen STATUS erlaubt,
                         * trotzdem die restliche Ordnerliste liefern.
                         */
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

    private function createClient(
        Mailbox $mailbox,
    ): Horde_Imap_Client_Socket {
        $password = $this->credentialService->decrypt(
            (string)$mailbox->getImapPassword()
        );

        return new Horde_Imap_Client_Socket([
            'username' => $mailbox->getImapUsername(),
            'password' => $password,
            'hostspec' => $mailbox->getImapHost(),
            'port' => $mailbox->getImapPort(),
            'secure' => $this->normalizeSecurity(
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
        return match (strtolower(trim($security))) {
            'ssl' => 'ssl',
            'tls' => 'tls',
            'none' => false,
            default => false,
        };
    }

    /**
     * @param string[] $attributes
     */
    private function detectSpecialUse(
        string $name,
        array $attributes,
    ): ?string {
        if (strcasecmp($name, 'INBOX') === 0) {
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

        foreach ($map as $attribute => $specialUse) {
            if (in_array($attribute, $attributes, true)) {
                return $specialUse;
            }
        }

        return null;
    }

    private function getFolderLabel(
        string $name,
        ?string $delimiter,
    ): string {
        if ($delimiter === null || $delimiter === '') {
            return $name;
        }

        $position = strrpos(
            $name,
            $delimiter
        );

        if ($position === false) {
            return $name;
        }

        return substr(
            $name,
            $position + strlen($delimiter)
        );
    }

    /**
     * Inbox zuerst, danach alphabetisch.
     */
    private function compareFolders(
        array $a,
        array $b,
    ): int {
        if ($a['specialUse'] === 'inbox'
            && $b['specialUse'] !== 'inbox') {
            return -1;
        }

        if ($b['specialUse'] === 'inbox'
            && $a['specialUse'] !== 'inbox') {
            return 1;
        }

        return strcasecmp(
            $a['name'],
            $b['name']
        );
    }
}