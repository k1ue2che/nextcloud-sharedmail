<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

use OCA\SharedMail\Db\MessageState;
use OCA\SharedMail\Db\MessageStateMapper;

class MessageStateMoveService
{
    public function __construct(
        private readonly MessageStateMapper $messageStateMapper,
    ) {
    }


    public function transfer(
        int $mailboxId,
        string $sourceFolder,
        int $sourceUid,
        string $targetFolder,
        int $targetUid
    ): bool {
        $sourceFolder =
            trim(
                $sourceFolder
            );

        $targetFolder =
            trim(
                $targetFolder
            );

        if (
            $mailboxId <= 0
            || $sourceUid <= 0
            || $targetUid <= 0
            || $sourceFolder === ''
            || $targetFolder === ''
        ) {
            return false;
        }

        $sourceState =
            $this
                ->messageStateMapper
                ->findOne(
                    $mailboxId,
                    $sourceFolder,
                    $sourceUid
                );

        /*
         * Kein gespeicherter Workflow-Zustand:
         *
         * Dann gilt die Nachricht implizit als NEW
         * und es gibt nichts zu übertragen.
         *
         * Auch im Ziel bleibt sie damit implizit NEW.
         */
        if ($sourceState === null) {
            return true;
        }

        $targetState =
            $this
                ->messageStateMapper
                ->findOne(
                    $mailboxId,
                    $targetFolder,
                    $targetUid
                );

        $isNew =
            $targetState === null;

        if ($targetState === null) {
            $targetState =
                new MessageState();

            $targetState->setMailboxId(
                $mailboxId
            );

            $targetState->setFolder(
                $targetFolder
            );

            $targetState->setUid(
                $targetUid
            );
        }

        /*
         * Gemeinsamen Workflow-Zustand übernehmen.
         */
        $targetState->setStatus(
            (string)$sourceState->getStatus()
        );

        $changedBy =
            $sourceState->getChangedBy();

        $targetState->setChangedBy(
            $changedBy !== null
                ? (string)$changedBy
                : null
        );

        $changedAt =
            $sourceState->getChangedAt();

        $targetState->setChangedAt(
            $changedAt !== null
                ? (int)$changedAt
                : null
        );

        if ($isNew) {
            $this
                ->messageStateMapper
                ->insert(
                    $targetState
                );
        } else {
            $this
                ->messageStateMapper
                ->update(
                    $targetState
                );
        }

        /*
         * Quellzustand erst entfernen, nachdem
         * der Zielzustand erfolgreich gespeichert wurde.
         */
        $this
            ->messageStateMapper
            ->delete(
                $sourceState
            );

        return true;
    }
}