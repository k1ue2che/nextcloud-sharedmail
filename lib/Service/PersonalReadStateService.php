<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

use OCA\SharedMail\Db\ReadState;
use OCA\SharedMail\Db\ReadStateMapper;
use OCP\IUserSession;

class PersonalReadStateService
{
    public function __construct(
        private readonly ReadStateMapper $readStateMapper,
        private readonly IUserSession $userSession,
    ) {
    }

    /**
     * @param int[] $uids
     * @return array<int, bool>
     */
    public function getStates(
        int $mailboxId,
        string $folder,
        array $uids
    ): array {
        $user =
            $this->userSession->getUser();

        if ($user === null) {
            return [];
        }

        return $this
            ->readStateMapper
            ->findStates(
                $mailboxId,
                $user->getUID(),
                $folder,
                $uids
            );
    }


    /**
     * Alle persönlichen Overrides eines Ordners.
     *
     * @return array<int, bool>
     */
    public function getFolderStates(
        int $mailboxId,
        string $folder
    ): array {
        $user =
            $this->userSession->getUser();

        if ($user === null) {
            return [];
        }

        $rows =
            $this
                ->readStateMapper
                ->findByFolder(
                    $mailboxId,
                    $user->getUID(),
                    $folder
                );

        $states = [];

        foreach ($rows as $row) {
            $uid =
                (int)$row->getUid();

            if ($uid <= 0) {
                continue;
            }

            $states[$uid] =
                (bool)$row->getIsRead();
        }

        return $states;
    }


    public function resolveIsRead(
        int $mailboxId,
        string $folder,
        int $uid,
        bool $imapSeen
    ): bool {
        $user =
            $this->userSession->getUser();

        if ($user === null) {
            return $imapSeen;
        }

        $state =
            $this
                ->readStateMapper
                ->findOne(
                    $mailboxId,
                    $user->getUID(),
                    $folder,
                    $uid
                );

        if ($state === null) {
            return $imapSeen;
        }

        return (bool)$state->getIsRead();
    }


    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    public function applyToMessages(
        int $mailboxId,
        string $folder,
        array $messages
    ): array {
        if ($messages === []) {
            return [];
        }

        $uids = [];

        foreach ($messages as $message) {
            $uid =
                (int)($message['uid'] ?? 0);

            if ($uid > 0) {
                $uids[] = $uid;
            }
        }

        $states =
            $this->getStates(
                $mailboxId,
                $folder,
                $uids
            );

        foreach ($messages as &$message) {
            $uid =
                (int)($message['uid'] ?? 0);

            $imapSeen =
                (bool)($message['seen'] ?? false);

            $message['imapSeen'] =
                $imapSeen;

            if (
                $uid > 0
                && array_key_exists(
                    $uid,
                    $states
                )
            ) {
                $message['seen'] =
                    $states[$uid];
            } else {
                $message['seen'] =
                    $imapSeen;
            }
        }

        unset($message);

        return $messages;
    }


    public function markRead(
        int $mailboxId,
        string $folder,
        int $uid
    ): bool {
        return $this->setState(
            $mailboxId,
            $folder,
            $uid,
            true
        );
    }


    public function markUnread(
        int $mailboxId,
        string $folder,
        int $uid
    ): bool {
        return $this->setState(
            $mailboxId,
            $folder,
            $uid,
            false
        );
    }


    private function setState(
        int $mailboxId,
        string $folder,
        int $uid,
        bool $isRead
    ): bool {
        $user =
            $this->userSession->getUser();

        if (
            $user === null
            || $mailboxId <= 0
            || $uid <= 0
            || trim($folder) === ''
        ) {
            return false;
        }

        $userId =
            $user->getUID();

        $folder =
            trim($folder);

        $now =
            time();

        $state =
            $this
                ->readStateMapper
                ->findOne(
                    $mailboxId,
                    $userId,
                    $folder,
                    $uid
                );

        /*
         * Noch kein persönlicher Override vorhanden.
         */
        if ($state === null) {
            $state =
                new ReadState();

            $state->setMailboxId(
                $mailboxId
            );

            $state->setUserId(
                $userId
            );

            $state->setFolder(
                $folder
            );

            $state->setUid(
                $uid
            );

            $state->setIsRead(
                $isRead
            );

            $state->setChangedAt(
                $now
            );

            /*
             * read_at stammt aus dem alten Schema
             * und ist NOT NULL.
             *
             * Deshalb muss auch ein erstmalig
             * erzeugter "ungelesen"-Override
             * einen gültigen Timestamp bekommen.
             */
            $state->setReadAt(
                $now
            );

            $this
                ->readStateMapper
                ->insert(
                    $state
                );

            return true;
        }


        /*
         * Vorhandenen persönlichen Override ändern.
         */
        $state->setIsRead(
            $isRead
        );

        $state->setChangedAt(
            $now
        );


        /*
         * Nur beim Markieren als gelesen
         * den letzten Lesezeitpunkt aktualisieren.
         *
         * Beim Markieren als ungelesen bleibt
         * read_at unverändert.
         */
        if ($isRead) {
            $state->setReadAt(
                $now
            );
        }


        $this
            ->readStateMapper
            ->update(
                $state
            );

        return true;
    }
}