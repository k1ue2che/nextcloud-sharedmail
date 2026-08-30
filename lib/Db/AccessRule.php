<?php

declare(strict_types=1);

namespace OCA\SharedMail\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

class AccessRule extends Entity
{
    protected int $mailboxId = 0;
    protected string $principalType = 'group';
    protected string $principalId = '';
    protected int $permissions = 0;
    protected int $createdAt = 0;

    public function __construct()
    {
        $this->addType('mailboxId', Types::BIGINT);
        $this->addType('permissions', Types::INTEGER);
        $this->addType('createdAt', Types::BIGINT);
    }
}