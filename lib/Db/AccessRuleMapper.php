<?php

declare(strict_types=1);

namespace OCA\SharedMail\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class AccessRuleMapper extends QBMapper
{
    public function __construct(
        IDBConnection $db,
    ) {
        parent::__construct(
            $db,
            'sharedmail_access',
            AccessRule::class
        );
    }

    /**
     * @return AccessRule[]
     */
    public function findByMailbox(
        int $mailboxId,
    ): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('sharedmail_access')
            ->where(
                $qb->expr()->eq(
                    'mailbox_id',
                    $qb->createNamedParameter(
                        $mailboxId,
                        IQueryBuilder::PARAM_INT
                    )
                )
            );

        return $this->findEntities($qb);
    }

    public function deleteByMailbox(
        int $mailboxId,
    ): void {
        $qb = $this->db->getQueryBuilder();

        $qb->delete('sharedmail_access')
            ->where(
                $qb->expr()->eq(
                    'mailbox_id',
                    $qb->createNamedParameter(
                        $mailboxId,
                        IQueryBuilder::PARAM_INT
                    )
                )
            );

        $qb->executeStatement();
    }

    /**
     * Ermittelt alle Mailbox-IDs, auf die mindestens eine
     * der übergebenen Nextcloud-Gruppen Zugriff hat.
     *
     * @param string[] $groupIds
     * @return int[]
     */
    public function findMailboxIdsForGroups(
        array $groupIds,
    ): array {
        $groupIds = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn ($groupId): string =>
                            trim((string)$groupId),
                        $groupIds
                    ),
                    static fn (string $groupId): bool =>
                        $groupId !== ''
                )
            )
        );

        if ($groupIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();

        $qb->selectDistinct('mailbox_id')
            ->from('sharedmail_access')
            ->where(
                $qb->expr()->eq(
                    'principal_type',
                    $qb->createNamedParameter(
                        'group',
                        IQueryBuilder::PARAM_STR
                    )
                )
            )
            ->andWhere(
                $qb->expr()->in(
                    'principal_id',
                    $qb->createNamedParameter(
                        $groupIds,
                        IQueryBuilder::PARAM_STR_ARRAY
                    )
                )
            );

        $result = $qb->executeQuery();

        $mailboxIds = [];

        try {
            while ($row = $result->fetchAssociative()) {
                $mailboxIds[] =
                    (int)$row['mailbox_id'];
            }
        } finally {
            $result->closeCursor();
        }

        $mailboxIds = array_values(
            array_unique($mailboxIds)
        );

        sort($mailboxIds);

        return $mailboxIds;
    }

    /**
     * Prüft, ob eine bestimmte Gruppe Zugriff
     * auf eine bestimmte Mailbox hat.
     */
    public function groupHasAccess(
        int $mailboxId,
        string $groupId,
    ): bool {
        $groupId = trim($groupId);

        if ($groupId === '') {
            return false;
        }

        $qb = $this->db->getQueryBuilder();

        $qb->select('id')
            ->from('sharedmail_access')
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
                    'principal_type',
                    $qb->createNamedParameter(
                        'group',
                        IQueryBuilder::PARAM_STR
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'principal_id',
                    $qb->createNamedParameter(
                        $groupId,
                        IQueryBuilder::PARAM_STR
                    )
                )
            )
            ->setMaxResults(1);

        $result = $qb->executeQuery();

        try {
            return $result->fetchOne() !== false;
        } finally {
            $result->closeCursor();
        }
    }
}