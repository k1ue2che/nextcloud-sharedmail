<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

use OCA\SharedMail\Db\Mailbox;
use OCP\IL10N;
use RuntimeException;

class MessageMoveService
{
    public function __construct(
        private readonly CredentialService $credentialService,
        private readonly IL10N $l,
    ) {
    }

    /**
     * Verschiebt eine Nachricht wirklich auf dem IMAP-Server.
     *
     * @return array{
     *     sourceFolder:string,
     *     sourceUid:int,
     *     targetFolder:string,
     *     targetUid:int
     * }
     */
    public function move(
        Mailbox $mailbox,
        string $sourceFolder,
        int $uid,
        string $targetFolder
    ): array {
        $sourceFolder = trim($sourceFolder);
        $targetFolder = trim($targetFolder);

        if ($sourceFolder === '') {
            throw new RuntimeException(
                $this->l->t(
                    'The source folder is missing.'
                )
            );
        }

        if ($targetFolder === '') {
            throw new RuntimeException(
                $this->l->t(
                    'The target folder is missing.'
                )
            );
        }

        if ($uid <= 0) {
            throw new RuntimeException(
                $this->l->t(
                    'Invalid message ID.'
                )
            );
        }

        if ($sourceFolder === $targetFolder) {
            throw new RuntimeException(
                $this->l->t(
                    'Source and target folder are identical.'
                )
            );
        }

        $client = $this->createClient(
            $mailbox
        );

        try {
            $client->login();

            /*
             * sequence = false:
             * Die ID ist eine IMAP-UID und keine
             * laufende Sequenznummer.
             */
            $ids = $client->getIdsOb(
                [$uid],
                false
            );

            /*
             * Horde verwendet copy() auch für MOVE.
             *
             * move       = Original entfernen
             * force_map  = alte UID -> neue UID
             */
            $mapping = $client->copy(
                $sourceFolder,
                $targetFolder,
                [
                    'ids' => $ids,
                    'move' => true,
                    'create' => false,
                    'force_map' => true,
                ]
            );

            if (!is_array($mapping)) {
                throw new RuntimeException(
                    $this->l->t(
                        'The IMAP server did not return a UID mapping.'
                    )
                );
            }

            $targetUid = 0;

            foreach (
                $mapping
                as $oldUid => $newUid
            ) {
                if ((int)$oldUid === $uid) {
                    $targetUid = (int)$newUid;
                    break;
                }
            }

            if ($targetUid <= 0) {
                throw new RuntimeException(
                    $this->l->t(
                        'The new UID of the moved message could not be determined.'
                    )
                );
            }

            return [
                'sourceFolder' =>
                    $sourceFolder,

                'sourceUid' =>
                    $uid,

                'targetFolder' =>
                    $targetFolder,

                'targetUid' =>
                    $targetUid,
            ];
        } finally {
            try {
                $client->logout();
            } catch (\Throwable) {
                // Verbindung wird ohnehin verworfen.
            }
        }
    }

    private function createClient(
        Mailbox $mailbox
    ): \Horde_Imap_Client_Socket {
        $security = strtolower(
            trim(
                (string)$mailbox->getImapSecurity()
            )
        );

        $secure = match ($security) {
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
                    (string)$mailbox->getImapPassword()
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
                    15,

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