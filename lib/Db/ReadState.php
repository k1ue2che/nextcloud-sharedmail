<?php

declare(strict_types=1);

namespace OCA\SharedMail\Db;

use OCP\AppFramework\Db\Entity;

class ReadState extends Entity
{
    protected $mailboxId;
    protected $userId;
    protected $folder;
    protected $uid;
    protected $readAt;

    public function __construct()
    {
        $this->addType(
            'id',
            'integer'
        );

        $this->addType(
            'mailboxId',
            'integer'
        );

        $this->addType(
            'userId',
            'string'
        );

        $this->addType(
            'folder',
            'string'
        );

        $this->addType(
            'uid',
            'integer'
        );

        $this->addType(
            'readAt',
            'integer'
        );
    }
}