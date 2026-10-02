<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

final class MessageStatus
{
    public const NEW =
        'NEW';

    public const OPEN =
        'OPEN';

    public const IN_PROGRESS =
        'IN_PROGRESS';

    public const WAITING =
        'WAITING';

    public const DONE =
        'DONE';


    /**
     * @var string[]
     */
    public const ALL = [
        self::NEW,
        self::OPEN,
        self::IN_PROGRESS,
        self::WAITING,
        self::DONE,
    ];


    private function __construct()
    {
    }


    public static function normalize(
        string $status
    ): ?string {
        $status =
            strtoupper(
                trim(
                    $status
                )
            );

        if (
            !in_array(
                $status,
                self::ALL,
                true
            )
        ) {
            return null;
        }

        return $status;
    }


    public static function isValid(
        string $status
    ): bool {
        return self::normalize(
            $status
        ) !== null;
    }
}