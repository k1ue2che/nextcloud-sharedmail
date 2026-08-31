<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

use Horde_Imap_Client_Socket;
use Horde_Smtp;
use Throwable;

class MailConnectionTestService
{
    /**
     * @return array{success: bool, message: string}
     */
    public function testImap(
        string $host,
        int $port,
        string $security,
        string $username,
        string $password,
    ): array {
        $client = null;

        try {
            $client = new Horde_Imap_Client_Socket([
                'username' => $username,
                'password' => $password,
                'hostspec' => $host,
                'port' => $port,
                'secure' => $this->normalizeSecurity($security),
                'timeout' => 10,
                'context' => [
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                    ],
                ],
            ]);

            $client->login();

            return [
                'success' => true,
                'message' => 'IMAP-Verbindung und Anmeldung erfolgreich.',
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => $this->buildErrorMessage('IMAP', $e),
            ];
        } finally {
            if ($client !== null) {
                try {
                    $client->logout();
                } catch (Throwable) {
                    // Beim Verbindungstest ignorieren.
                }
            }
        }
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function testSmtp(
        string $host,
        int $port,
        string $security,
        string $username,
        string $password,
    ): array {
        $client = null;

        try {
            $client = new Horde_Smtp([
                'host' => $host,
                'port' => $port,
                'secure' => $this->normalizeSecurity($security),
                'username' => $username,
                'password' => $password,
                'timeout' => 10,
                'context' => [
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                    ],
                ],
            ]);

            $client->login();

            return [
                'success' => true,
                'message' => 'SMTP-Verbindung und Anmeldung erfolgreich.',
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => $this->buildErrorMessage('SMTP', $e),
            ];
        } finally {
            if ($client !== null) {
                try {
                    $client->logout();
                } catch (Throwable) {
                    // Beim Verbindungstest ignorieren.
                }
            }
        }
    }

    private function normalizeSecurity(string $security): string|false
    {
        return match (strtolower(trim($security))) {
            'ssl' => 'ssl',
            'tls' => 'tls',
            'none' => false,
            default => false,
        };
    }

    private function buildErrorMessage(
        string $protocol,
        Throwable $e,
    ): string {
        $message = trim($e->getMessage());

        if ($message === '') {
            return $protocol . '-Verbindung fehlgeschlagen.';
        }

        return $protocol . ': ' . $message;
    }
}