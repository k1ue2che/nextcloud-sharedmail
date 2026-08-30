<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

use OCP\Security\ICrypto;

class CredentialService
{
    public function __construct(
        private readonly ICrypto $crypto,
    ) {
    }

    public function encrypt(string $password): string
    {
        if ($password === '') {
            return '';
        }

        return $this->crypto->encrypt($password);
    }

    public function decrypt(string $password): string
    {
        if ($password === '') {
            return '';
        }

        return $this->crypto->decrypt($password);
    }
}