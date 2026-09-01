<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

use OCA\SharedMail\Db\Mailbox;

class PersonalFolderCountService
{
    public function __construct(
        private readonly PersonalReadStateService $personalReadStateService,
        private readonly CredentialService $credentialService,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $folders
     * @return array<int, array<string, mixed>>
     */
    public function apply(
        Mailbox $mailbox,
        int $mailboxId,
        array $folders
    ): array {
        $folderStates = [];

        foreach ($folders as &$folder) {
            $imapUnseen = (int)($folder['unseen'] ?? 0);

            $folder['imapUnseen'] = $imapUnseen;
            $folder['personalUnseen'] = $imapUnseen;

            $folderName = trim(
                (string)($folder['name'] ?? '')
            );

            $selectable =
                (bool)($folder['selectable'] ?? false);

            $messages =
                (int)($folder['messages'] ?? 0);

            if (
                $folderName === ''
                || !$selectable
                || $messages <= 0
            ) {
                continue;
            }

            $states =
                $this
                    ->personalReadStateService
                    ->getFolderStates(
                        $mailboxId,
                        $folderName
                    );

            if ($states !== []) {
                $folderStates[$folderName] =
                    $states;
            }
        }

        unset($folder);

        if ($folderStates === []) {
            return $folders;
        }

        $client = null;

        try {
            $client =
                $this->createClient(
                    $mailbox
                );

            $client->login();

            foreach ($folders as &$folder) {
                $folderName = trim(
                    (string)($folder['name'] ?? '')
                );

                if (
                    $folderName === ''
                    || !isset(
                        $folderStates[$folderName]
                    )
                ) {
                    continue;
                }

                $states =
                    $folderStates[$folderName];

                $imapSeenStates =
                    $this->getImapSeenStates(
                        $client,
                        $folderName,
                        array_keys($states)
                    );

                $personalUnseen =
                    (int)(
                        $folder['imapUnseen']
                        ?? $folder['unseen']
                        ?? 0
                    );

                foreach (
                    $states
                    as $uid => $personalRead
                ) {
                    $uid = (int)$uid;

                    /*
                     * Nachricht existiert nicht mehr
                     * in diesem IMAP-Ordner.
                     */
                    if (
                        !array_key_exists(
                            $uid,
                            $imapSeenStates
                        )
                    ) {
                        continue;
                    }

                    $imapSeen =
                        $imapSeenStates[$uid];

                    /*
                     * IMAP ungelesen,
                     * persönlich gelesen.
                     */
                    if (
                        !$imapSeen
                        && $personalRead
                    ) {
                        $personalUnseen--;
                        continue;
                    }

                    /*
                     * IMAP gelesen,
                     * persönlich ungelesen.
                     */
                    if (
                        $imapSeen
                        && !$personalRead
                    ) {
                        $personalUnseen++;
                    }
                }

                $messages =
                    (int)($folder['messages'] ?? 0);

                $personalUnseen =
                    max(
                        0,
                        min(
                            $messages,
                            $personalUnseen
                        )
                    );

                $folder['personalUnseen'] =
                    $personalUnseen;

                /*
                 * Das bestehende Frontend zeigt
                 * bereits "unseen" an.
                 */
                $folder['unseen'] =
                    $personalUnseen;
            }

            unset($folder);
        } catch (\Throwable) {
            /*
             * Personalisierung darf die Ordnerliste
             * nicht komplett zerstören.
             *
             * Bei Fehler bleibt der globale
             * IMAP-Zähler erhalten.
             */
            return $folders;
        } finally {
            if ($client !== null) {
                try {
                    $client->logout();
                } catch (\Throwable) {
                    // Ignorieren.
                }
            }
        }

        return $folders;
    }

    /**
     * @param int[] $uids
     * @return array<int, bool>
     */
    private function getImapSeenStates(
        object $client,
        string $folder,
        array $uids
    ): array {
        $uids = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'intval',
                        $uids
                    ),
                    static fn (int $uid): bool =>
                        $uid > 0
                )
            )
        );

        if ($uids === []) {
            return [];
        }

        $query =
            new \Horde_Imap_Client_Fetch_Query();

        $query->flags();
        $query->uid();

        $ids =
            new \Horde_Imap_Client_Ids(
                $uids
            );

        $results =
            $client->fetch(
                $folder,
                $query,
                [
                    'ids' => $ids,
                ]
            );

        $seenStates = [];

        foreach ($results as $result) {
            $uid =
                (int)$result->getUid();

            if ($uid <= 0) {
                continue;
            }

            $flags =
                array_map(
                    static fn ($flag): string =>
                        strtolower(
                            (string)$flag
                        ),
                    $result->getFlags()
                );

            $seenStates[$uid] =
                in_array(
                    '\\seen',
                    $flags,
                    true
                );
        }

        return $seenStates;
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
                    (string)
                    $mailbox->getImapUsername(),

                'password' =>
                    $password,

                'hostspec' =>
                    (string)
                    $mailbox->getImapHost(),

                'port' =>
                    (int)
                    $mailbox->getImapPort(),

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