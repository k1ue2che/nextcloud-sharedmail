<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

use InvalidArgumentException;
use OCA\SharedMail\Db\MessageState;
use OCA\SharedMail\Db\MessageStateMapper;
use OCP\IL10N;
use OCP\IUserSession;
use RuntimeException;

class MessageStateService
{
    public function __construct(
        private readonly MessageStateMapper $messageStateMapper,
        private readonly IUserSession $userSession,
        private readonly IL10N $l,
    ) {
    }


    /**
     * Gemeinsamer Status einer einzelnen Nachricht.
     *
     * Existiert noch kein DB-Eintrag, gilt die
     * Nachricht automatisch als NEW.
     *
     * @return array{
     *     status:string,
     *     changedBy:?string,
     *     changedAt:?int
     * }
     */
    public function getState(
        int $mailboxId,
        string $folder,
        int $uid
    ): array {
        $folder =
            trim(
                $folder
            );

        if (
            $mailboxId <= 0
            || $uid <= 0
            || $folder === ''
        ) {
            return $this->defaultState();
        }

        $state =
            $this
                ->messageStateMapper
                ->findOne(
                    $mailboxId,
                    $folder,
                    $uid
                );

        if ($state === null) {
            return $this->defaultState();
        }

        return $this->toArray(
            $state
        );
    }


    /**
     * Gemeinsame Zustände mehrerer Nachrichten.
     *
     * Nur vorhandene DB-Zustände werden geladen.
     * Fehlende UIDs erhalten automatisch NEW.
     *
     * @param int[] $uids
     * @return array<int, array{
     *     status:string,
     *     changedBy:?string,
     *     changedAt:?int
     * }>
     */
    public function getStates(
        int $mailboxId,
        string $folder,
        array $uids
    ): array {
        $folder =
            trim(
                $folder
            );

        $uids =
            array_values(
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

        if (
            $mailboxId <= 0
            || $folder === ''
            || $uids === []
        ) {
            return [];
        }

        $entities =
            $this
                ->messageStateMapper
                ->findStates(
                    $mailboxId,
                    $folder,
                    $uids
                );

        $states = [];

        foreach ($uids as $uid) {
            if (
                isset(
                    $entities[$uid]
                )
            ) {
                $states[$uid] =
                    $this->toArray(
                        $entities[$uid]
                    );

                continue;
            }

            $states[$uid] =
                $this->defaultState();
        }

        return $states;
    }


    /**
     * Workflow-Zustände direkt an die
     * IMAP-Nachrichten hängen.
     *
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
                (int)(
                    $message['uid']
                    ?? 0
                );

            if ($uid > 0) {
                $uids[] =
                    $uid;
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
                (int)(
                    $message['uid']
                    ?? 0
                );

            $state =
                $states[$uid]
                ?? $this->defaultState();

            $message['workflowStatus'] =
                $state['status'];

            $message['workflowChangedBy'] =
                $state['changedBy'];

            $message['workflowChangedAt'] =
                $state['changedAt'];
        }

        unset(
            $message
        );

        return $messages;
    }


    /**
     * Gemeinsamen Workflow-Status ändern.
     *
     * @return array{
     *     status:string,
     *     changedBy:?string,
     *     changedAt:?int
     * }
     */
    public function setStatus(
        int $mailboxId,
        string $folder,
        int $uid,
        string $status
    ): array {
        $folder =
            trim(
                $folder
            );

        $normalizedStatus =
            MessageStatus::normalize(
                $status
            );

        if (
            $mailboxId <= 0
            || $uid <= 0
            || $folder === ''
        ) {
            throw new InvalidArgumentException(
                $this->l->t('Invalid message.')
            );
        }

        if ($normalizedStatus === null) {
            throw new InvalidArgumentException(
                $this->l->t('Invalid message status.')
            );
        }

        $user =
            $this
                ->userSession
                ->getUser();

        if ($user === null) {
            throw new RuntimeException(
                $this->l->t('No signed-in user.')
            );
        }

        $now =
            time();

        $state =
            $this
                ->messageStateMapper
                ->findOne(
                    $mailboxId,
                    $folder,
                    $uid
                );

        if ($state === null) {
            $state =
                new MessageState();

            $state->setMailboxId(
                $mailboxId
            );

            $state->setFolder(
                $folder
            );

            $state->setUid(
                $uid
            );

            $state->setStatus(
                $normalizedStatus
            );

            $state->setChangedBy(
                $user->getUID()
            );

            $state->setChangedAt(
                $now
            );

            $state =
                $this
                    ->messageStateMapper
                    ->insert(
                        $state
                    );
        } else {
            $state->setStatus(
                $normalizedStatus
            );

            $state->setChangedBy(
                $user->getUID()
            );

            $state->setChangedAt(
                $now
            );

            $state =
                $this
                    ->messageStateMapper
                    ->update(
                        $state
                    );
        }

        return $this->toArray(
            $state
        );
    }


    public function deleteByMailbox(
        int $mailboxId
    ): int {
        if ($mailboxId <= 0) {
            return 0;
        }

        return $this
            ->messageStateMapper
            ->deleteByMailbox(
                $mailboxId
            );
    }


    /**
     * @return array{
     *     status:string,
     *     changedBy:?string,
     *     changedAt:?int
     * }
     */
    private function defaultState(): array
    {
        return [
            'status' =>
                MessageStatus::NEW,

            'changedBy' =>
                null,

            'changedAt' =>
                null,
        ];
    }


    /**
     * @return array{
     *     status:string,
     *     changedBy:?string,
     *     changedAt:?int
     * }
     */
    private function toArray(
        MessageState $state
    ): array {
        return [
            'status' =>
                MessageStatus::normalize(
                    (string)$state->getStatus()
                )
                ?? MessageStatus::NEW,

            'changedBy' =>
                $state->getChangedBy() !== null
                    ? (string)$state->getChangedBy()
                    : null,

            'changedAt' =>
                $state->getChangedAt() !== null
                    ? (int)$state->getChangedAt()
                    : null,
        ];
    }
}