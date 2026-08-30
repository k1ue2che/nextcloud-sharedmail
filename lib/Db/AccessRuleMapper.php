<?php

declare(strict_types=1);

namespace OCA\SharedMail\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<AccessRule>
 */
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
    public function findByMailbox(int $mailboxId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
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
}