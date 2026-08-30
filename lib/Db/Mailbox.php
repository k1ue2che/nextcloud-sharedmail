<?php

declare(strict_types=1);

namespace OCA\SharedMail\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

class Mailbox extends Entity
{
    protected string $name = '';
    protected ?string $description = null;
    protected string $email = '';

    protected string $imapHost = '';
    protected int $imapPort = 993;
    protected string $imapSecurity = 'ssl';
    protected string $imapUsername = '';
    protected ?string $imapPassword = null;

    protected string $smtpHost = '';
    protected int $smtpPort = 465;
    protected string $smtpSecurity = 'ssl';
    protected string $smtpUsername = '';
    protected ?string $smtpPassword = null;

    protected bool $enabled = true;

    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    public function __construct()
    {
        $this->addType('imapPort', Types::INTEGER);
        $this->addType('smtpPort', Types::INTEGER);
        $this->addType('enabled', Types::BOOLEAN);
        $this->addType('createdAt', Types::BIGINT);
        $this->addType('updatedAt', Types::BIGINT);
    }
}