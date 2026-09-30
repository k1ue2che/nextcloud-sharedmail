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
     * Normalisiert eine Liste von Nextcloud-Gruppen-IDs.
     *
     * @param string[] $groupIds
     * @return string[]
     */
    private function normalizeGroupIds(
        array $groupIds,
    ): array {
        return array_values(
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
    }

    /**
     * Ermittelt die effektiven Rechte pro Mailbox
     * für eine Liste von Nextcloud-Gruppen.
     *
     * Ist ein Benutzer Mitglied mehrerer Gruppen,
     * werden die Rechte aller passenden Regeln
     * bitweise zusammengeführt.
     *
     * Beispiel:
     *
     * Gruppe A:
     * READ | REPLY
     *
     * Gruppe B:
     * COMPOSE | MOVE
     *
     * Ergebnis:
     * READ | REPLY | COMPOSE | MOVE
     *
     * @param string[] $groupIds
     * @return array<int, int>
     */
    public function findPermissionsByMailboxForGroups(
        array $groupIds,
    ): array {
        $groupIds =
            $this->normalizeGroupIds(
                $groupIds
            );

        if ($groupIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();

        $qb->select(
            'mailbox_id',
            'permissions'
        )
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

        $result =
            $qb->executeQuery();

        $permissionsByMailbox = [];

        try {
            while (
                $row =
                    $result->fetchAssociative()
            ) {
                $mailboxId =
                    (int)(
                        $row['mailbox_id']
                        ?? 0
                    );

                $permissions =
                    (int)(
                        $row['permissions']
                        ?? 0
                    );

                if ($mailboxId <= 0) {
                    continue;
                }

                if (
                    !isset(
                        $permissionsByMailbox[
                            $mailboxId
                        ]
                    )
                ) {
                    $permissionsByMailbox[
                        $mailboxId
                    ] = 0;
                }

                $permissionsByMailbox[
                    $mailboxId
                ] |= $permissions;
            }
        } finally {
            $result->closeCursor();
        }

        ksort(
            $permissionsByMailbox
        );

        return $permissionsByMailbox;
    }

    /**
     * Ermittelt die effektiven Rechte einer
     * bestimmten Mailbox für mehrere Gruppen.
     *
     * @param string[] $groupIds
     */
    public function getPermissionsForMailboxAndGroups(
        int $mailboxId,
        array $groupIds,
    ): int {
        if ($mailboxId <= 0) {
            return 0;
        }

        $groupIds =
            $this->normalizeGroupIds(
                $groupIds
            );

        if ($groupIds === []) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();

        $qb->select(
            'permissions'
        )
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
                $qb->expr()->in(
                    'principal_id',
                    $qb->createNamedParameter(
                        $groupIds,
                        IQueryBuilder::PARAM_STR_ARRAY
                    )
                )
            );

        $result =
            $qb->executeQuery();

        $permissions = 0;

        try {
            while (
                $row =
                    $result->fetchAssociative()
            ) {
                $permissions |=
                    (int)(
                        $row['permissions']
                        ?? 0
                    );
            }
        } finally {
            $result->closeCursor();
        }

        return $permissions;
    }

    /**
     * Ermittelt alle Mailbox-IDs, für die
     * mindestens ein Recht vorhanden ist.
     *
     * @param string[] $groupIds
     * @return int[]
     */
    public function findMailboxIdsForGroups(
        array $groupIds,
    ): array {
        $permissionsByMailbox =
            $this->findPermissionsByMailboxForGroups(
                $groupIds
            );

        return array_values(
            array_map(
                'intval',
                array_keys(
                    $permissionsByMailbox
                )
            )
        );
    }

    /**
     * Prüft, ob eine bestimmte Gruppe mindestens
     * ein Recht auf eine bestimmte Mailbox besitzt.
     */
    public function groupHasAccess(
        int $mailboxId,
        string $groupId,
    ): bool {
        $groupId =
            trim(
                $groupId
            );

        if ($groupId === '') {
            return false;
        }

        return (
            $this->getPermissionsForMailboxAndGroups(
                $mailboxId,
                [
                    $groupId,
                ]
            )
            !== 0
        );
    }
}