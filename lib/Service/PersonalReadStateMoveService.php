<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

use OCA\SharedMail\Db\ReadState;
use OCA\SharedMail\Db\ReadStateMapper;
use OCP\IUserSession;

class PersonalReadStateMoveService
{
    public function __construct(
        private readonly ReadStateMapper $readStateMapper,
        private readonly IUserSession $userSession,
    ) {
    }

    public function transfer(
        int $mailboxId,
        string $sourceFolder,
        int $sourceUid,
        string $targetFolder,
        int $targetUid
    ): bool {
        $user =
            $this->userSession->getUser();

        if ($user === null) {
            return false;
        }

        $userId =
            $user->getUID();

        $sourceState =
            $this
                ->readStateMapper
                ->findOne(
                    $mailboxId,
                    $userId,
                    $sourceFolder,
                    $sourceUid
                );

        /*
         * Kein persönlicher Override:
         *
         * Dann gibt es auch nichts zu übertragen.
         * Im Ziel gilt wieder der IMAP-Status.
         */
        if ($sourceState === null) {
            return true;
        }

        $targetState =
            $this
                ->readStateMapper
                ->findOne(
                    $mailboxId,
                    $userId,
                    $targetFolder,
                    $targetUid
                );

        $isNew =
            $targetState === null;

        if ($targetState === null) {
            $targetState =
                new ReadState();

            $targetState->setMailboxId(
                $mailboxId
            );

            $targetState->setUserId(
                $userId
            );

            $targetState->setFolder(
                $targetFolder
            );

            $targetState->setUid(
                $targetUid
            );
        }

        /*
         * Persönlichen Zustand übernehmen.
         */
        $targetState->setIsRead(
            (bool)$sourceState->getIsRead()
        );

        $targetState->setChangedAt(
            (int)$sourceState->getChangedAt()
        );

        $readAt =
            $sourceState->getReadAt();

        $targetState->setReadAt(
            $readAt !== null
                ? (int)$readAt
                : null
        );

        if ($isNew) {
            $this
                ->readStateMapper
                ->insert(
                    $targetState
                );
        } else {
            $this
                ->readStateMapper
                ->update(
                    $targetState
                );
        }

        /*
         * Alten Override erst löschen,
         * nachdem der neue gespeichert wurde.
         */
        $this
            ->readStateMapper
            ->delete(
                $sourceState
            );

        return true;
    }
}