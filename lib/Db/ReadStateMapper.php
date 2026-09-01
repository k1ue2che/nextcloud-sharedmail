<?php

declare(strict_types=1);

namespace OCA\SharedMail\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class ReadStateMapper extends QBMapper
{
    public function __construct(
        IDBConnection $db,
    ) {
        parent::__construct(
            $db,
            'sharedmail_read_state',
            ReadState::class
        );
    }

    public function findOne(
        int $mailboxId,
        string $userId,
        string $folder,
        int $uid,
    ): ?ReadState {
        $qb =
            $this->db
                ->getQueryBuilder();

        $qb->select('*')
            ->from(
                'sharedmail_read_state'
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
                    'user_id',
                    $qb->createNamedParameter(
                        $userId,
                        IQueryBuilder::PARAM_STR
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'folder',
                    $qb->createNamedParameter(
                        $folder,
                        IQueryBuilder::PARAM_STR
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
     * Liefert von einer gegebenen UID-Liste nur
     * diejenigen zurück, die der Benutzer bereits
     * persönlich gelesen hat.
     *
     * @param int[] $uids
     * @return int[]
     */
    public function findReadUids(
        int $mailboxId,
        string $userId,
        string $folder,
        array $uids,
    ): array {
        $uids =
            array_values(
                array_unique(
                    array_filter(
                        array_map(
                            static fn (
                                mixed $uid
                            ): int =>
                                (int)$uid,
                            $uids
                        ),
                        static fn (
                            int $uid
                        ): bool =>
                            $uid > 0
                    )
                )
            );

        if ($uids === []) {
            return [];
        }

        $qb =
            $this->db
                ->getQueryBuilder();

        $qb->select('uid')
            ->from(
                'sharedmail_read_state'
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
                    'user_id',
                    $qb->createNamedParameter(
                        $userId,
                        IQueryBuilder::PARAM_STR
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'folder',
                    $qb->createNamedParameter(
                        $folder,
                        IQueryBuilder::PARAM_STR
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

        $result =
            $qb->executeQuery();

        $readUids = [];

        try {
            while (
                $row =
                    $result
                        ->fetchAssociative()
            ) {
                $uid =
                    (int)$row['uid'];

                if ($uid > 0) {
                    $readUids[] =
                        $uid;
                }
            }
        } finally {
            $result->closeCursor();
        }

        return array_values(
            array_unique(
                $readUids
            )
        );
    }


    public function deleteOne(
        int $mailboxId,
        string $userId,
        string $folder,
        int $uid,
    ): void {
        $qb =
            $this->db
                ->getQueryBuilder();

        $qb->delete(
            'sharedmail_read_state'
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
                    'user_id',
                    $qb->createNamedParameter(
                        $userId,
                        IQueryBuilder::PARAM_STR
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'folder',
                    $qb->createNamedParameter(
                        $folder,
                        IQueryBuilder::PARAM_STR
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

        $qb->executeStatement();
    }
}