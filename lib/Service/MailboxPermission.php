<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

final class MailboxPermission
{
    public const READ = 1;
    public const REPLY = 2;
    public const COMPOSE = 4;
    public const MOVE = 8;
    public const DELETE = 16;
    public const ASSIGN = 32;
    public const CHANGE_STATUS = 64;
    public const MANAGE = 128;

    /*
     * Standardrechte einer neu zugewiesenen Gruppe.
     *
     * MOVE gehört bewusst dazu, damit das bisherige
     * Verhalten von Shared Mail erhalten bleibt.
     */
    public const DEFAULT =
        self::READ |
        self::REPLY |
        self::COMPOSE |
        self::MOVE;

    public const FULL =
        self::READ |
        self::REPLY |
        self::COMPOSE |
        self::MOVE |
        self::DELETE |
        self::ASSIGN |
        self::CHANGE_STATUS |
        self::MANAGE;
}