<?php

declare(strict_types=1);

namespace OCA\SharedMail\Db;

use OCP\AppFramework\Db\Entity;

class AccessRule extends Entity
{
    protected $mailboxId;
    protected $principalType;
    protected $principalId;
    protected $permissions;
    protected $createdAt;

    public function __construct()
    {
        $this->addType('id', 'integer');
        $this->addType('mailboxId', 'integer');
        $this->addType('principalType', 'string');
        $this->addType('principalId', 'string');
        $this->addType('permissions', 'integer');
        $this->addType('createdAt', 'integer');
    }
}