<?php

declare(strict_types=1);

namespace OCA\SharedMail\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'sharedmail';

    public function __construct(
        array $urlParams = [],
    ) {
        parent::__construct(
            self::APP_ID,
            $urlParams
        );
    }

    public function register(
        IRegistrationContext $context,
    ): void {
        /*
         * Drittanbieter-Abhängigkeiten von Shared Mail.
         *
         * Dazu gehören insbesondere die verwendeten
         * Horde-Pakete für IMAP, SMTP und MIME.
         *
         * Wichtig:
         * Ein fehlender vendor/autoload.php darf nicht
         * die komplette Nextcloud-App-Initialisierung
         * mit einem PHP-Fatal-Error abbrechen.
         */
        $autoload =
            __DIR__
            . '/../../vendor/autoload.php';

        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    public function boot(
        IBootContext $context,
    ): void {
        // Aktuell keine Boot-Logik notwendig.
    }
}