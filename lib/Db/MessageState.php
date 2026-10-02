<?php

declare(strict_types=1);

namespace OCA\SharedMail\Db;

use OCP\AppFramework\Db\Entity;

class MessageState extends Entity
{
    protected $mailboxId;
    protected $folder;
    protected $uid;
    protected $status;
    protected $changedBy;
    protected $changedAt;


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
            'folder',
            'string'
        );

        $this->addType(
            'uid',
            'integer'
        );

        $this->addType(
            'status',
            'string'
        );

        $this->addType(
            'changedBy',
            'string'
        );

        $this->addType(
            'changedAt',
            'integer'
        );
    }
}