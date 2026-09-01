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
        IDBConnection $db
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
        int $uid
    ): ?ReadState {
        $qb = $this->db->getQueryBuilder();

        $qb
            ->select('*')
            ->from($this->getTableName())
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
                        $userId
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
     * Liefert die expliziten persönlichen Zustände
     * für mehrere IMAP-UIDs.
     *
     * Rückgabe:
     *
     * [
     *     123 => true,
     *     124 => false,
     * ]
     *
     * Fehlende UID:
     * kein persönlicher Override vorhanden.
     *
     * @param int[] $uids
     * @return array<int, bool>
     */
    public function findStates(
        int $mailboxId,
        string $userId,
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

        $qb = $this->db->getQueryBuilder();

        $qb
            ->select(
                'uid',
                'is_read'
            )
            ->from($this->getTableName())
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
                        $userId
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

        $result =
            $qb->executeQuery();

        $states = [];

        while (
            $row =
                $result->fetchAssociative()
        ) {
            $uid =
                (int)$row['uid'];

            $states[$uid] =
                (bool)$row['is_read'];
        }

        $result->closeCursor();

        return $states;
    }

    /**
     * Alle persönlichen Zustände eines Ordners.
     *
     * Wird später für den persönlichen
     * Ungelesen-Zähler benötigt.
     *
     * @return ReadState[]
     */
    public function findByFolder(
        int $mailboxId,
        string $userId,
        string $folder
    ): array {
        $qb = $this->db->getQueryBuilder();

        $qb
            ->select('*')
            ->from($this->getTableName())
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
                        $userId
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
        $qb = $this->db->getQueryBuilder();

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