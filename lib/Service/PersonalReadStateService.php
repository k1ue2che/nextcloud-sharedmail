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
     * @return int[]
     */
    public function getReadUids(
        int $mailboxId,
        string $folder,
        array $uids,
    ): array {
        $user =
            $this->userSession
                ->getUser();

        if ($user === null) {
            return [];
        }

        $folder =
            trim($folder);

        if (
            $mailboxId <= 0
            || $folder === ''
            || $uids === []
        ) {
            return [];
        }

        return $this
            ->readStateMapper
            ->findReadUids(
                $mailboxId,
                $user->getUID(),
                $folder,
                $uids
            );
    }


    public function isRead(
        int $mailboxId,
        string $folder,
        int $uid,
    ): bool {
        $user =
            $this->userSession
                ->getUser();

        if ($user === null) {
            return false;
        }

        $folder =
            trim($folder);

        if (
            $mailboxId <= 0
            || $folder === ''
            || $uid <= 0
        ) {
            return false;
        }

        return $this
            ->readStateMapper
            ->findOne(
                $mailboxId,
                $user->getUID(),
                $folder,
                $uid
            ) !== null;
    }


    public function markRead(
        int $mailboxId,
        string $folder,
        int $uid,
    ): bool {
        $user =
            $this->userSession
                ->getUser();

        if ($user === null) {
            return false;
        }

        $folder =
            trim($folder);

        if (
            $mailboxId <= 0
            || $folder === ''
            || $uid <= 0
        ) {
            return false;
        }

        $userId =
            $user->getUID();

        $existing =
            $this->readStateMapper
                ->findOne(
                    $mailboxId,
                    $userId,
                    $folder,
                    $uid
                );

        /*
         * Zeile existiert bereits:
         * Die Nachricht ist für diesen Benutzer
         * schon gelesen.
         *
         * read_at bleibt bewusst der Zeitpunkt
         * des ersten Lesens.
         */
        if ($existing !== null) {
            return true;
        }

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

        $state->setReadAt(
            time()
        );

        $this->readStateMapper
            ->insert(
                $state
            );

        return true;
    }


    public function markUnread(
        int $mailboxId,
        string $folder,
        int $uid,
    ): bool {
        $user =
            $this->userSession
                ->getUser();

        if ($user === null) {
            return false;
        }

        $folder =
            trim($folder);

        if (
            $mailboxId <= 0
            || $folder === ''
            || $uid <= 0
        ) {
            return false;
        }

        /*
         * Kein Datensatz = persönlich ungelesen.
         */
        $this->readStateMapper
            ->deleteOne(
                $mailboxId,
                $user->getUID(),
                $folder,
                $uid
            );

        return true;
    }
}