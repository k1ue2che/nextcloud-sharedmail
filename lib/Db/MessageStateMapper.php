<?php

declare(strict_types=1);

namespace OCA\SharedMail\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class MessageStateMapper extends QBMapper
{
    public function __construct(
        IDBConnection $db
    ) {
        parent::__construct(
            $db,
            'sharedmail_message_state',
            MessageState::class
        );
    }


    public function findOne(
        int $mailboxId,
        string $folder,
        int $uid
    ): ?MessageState {
        $qb =
            $this->db->getQueryBuilder();

        $qb
            ->select('*')
            ->from(
                $this->getTableName()
            )
            ->where(
                $qb->expr()->eq(
                    'mailbox_id',
                    $qb->createNamedParameter(
                        $mailboxId,
                        IQueryBuilder::PARAM_INT
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'folder',
                    $qb->createNamedParameter(
                        $folder
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'uid',
                    $qb->createNamedParameter(
                        $uid,
                        IQueryBuilder::PARAM_INT
                    )
                )
            );

        try {
            return $this->findEntity(
                $qb
            );
        } catch (
            DoesNotExistException
            | MultipleObjectsReturnedException
        ) {
            return null;
        }
    }


    /**
     * Gemeinsame Workflow-Zustände mehrerer
     * Nachrichten eines Ordners.
     *
     * @param int[] $uids
     * @return array<int, MessageState>
     */
    public function findStates(
        int $mailboxId,
        string $folder,
        array $uids
    ): array {
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

        if ($uids === []) {
            return [];
        }

        $qb =
            $this->db->getQueryBuilder();

        $qb
            ->select('*')
            ->from(
                $this->getTableName()
            )
            ->where(
                $qb->expr()->eq(
                    'mailbox_id',
                    $qb->createNamedParameter(
                        $mailboxId,
                        IQueryBuilder::PARAM_INT
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'folder',
                    $qb->createNamedParameter(
                        $folder
                    )
                )
            )
            ->andWhere(
                $qb->expr()->in(
                    'uid',
                    $qb->createNamedParameter(
                        $uids,
                        IQueryBuilder::PARAM_INT_ARRAY
                    )
                )
            );

        $entities =
            $this->findEntities(
                $qb
            );

        $states = [];

        foreach ($entities as $entity) {
            $uid =
                (int)$entity->getUid();

            if ($uid <= 0) {
                continue;
            }

            $states[$uid] =
                $entity;
        }

        return $states;
    }


    /**
     * @return MessageState[]
     */
    public function findByFolder(
        int $mailboxId,
        string $folder
    ): array {
        $qb =
            $this->db->getQueryBuilder();

        $qb
            ->select('*')
            ->from(
                $this->getTableName()
            )
            ->where(
                $qb->expr()->eq(
                    'mailbox_id',
                    $qb->createNamedParameter(
                        $mailboxId,
                        IQueryBuilder::PARAM_INT
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'folder',
                    $qb->createNamedParameter(
                        $folder
                    )
                )
            );

        return $this->findEntities(
            $qb
        );
    }


    public function deleteByMailbox(
        int $mailboxId
    ): int {
        $qb =
            $this->db->getQueryBuilder();

        $qb
            ->delete(
                $this->getTableName()
            )
            ->where(
                $qb->expr()->eq(
                    'mailbox_id',
                    $qb->createNamedParameter(
                        $mailboxId,
                        IQueryBuilder::PARAM_INT
                    )
                )
            );

        return $qb->executeStatement();
    }
}