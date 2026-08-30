<?php

declare(strict_types=1);

namespace OCA\SharedMail\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Mailbox>
 */
class MailboxMapper extends QBMapper
{
    public function __construct(
        IDBConnection $db,
    ) {
        parent::__construct(
            $db,
            'sharedmail_mailboxes',
            Mailbox::class
        );
    }

    public function find(int $id): Mailbox
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'id',
                    $qb->createNamedParameter(
                        $id,
                        IQueryBuilder::PARAM_INT
                    )
                )
            );

        return $this->findEntity($qb);
    }

    /**
     * @return Mailbox[]
     */
    public function findAll(): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('name', 'ASC');

        return $this->findEntities($qb);
    }
}