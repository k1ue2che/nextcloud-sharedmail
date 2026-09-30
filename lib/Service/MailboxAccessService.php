<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

use OCA\SharedMail\Db\AccessRuleMapper;
use OCA\SharedMail\Db\Mailbox;
use OCA\SharedMail\Db\MailboxMapper;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;

class MailboxAccessService
{
    public function __construct(
        private readonly MailboxMapper $mailboxMapper,
        private readonly AccessRuleMapper $accessRuleMapper,
        private readonly IGroupManager $groupManager,
        private readonly IUserSession $userSession,
    ) {
    }

    /**
     * @return Mailbox[]
     */
    public function getAccessibleMailboxes(): array
    {
        $user =
            $this->userSession->getUser();

        if ($user === null) {
            return [];
        }

        return $this->getAccessibleMailboxesForUser(
            $user
        );
    }

    /**
     * Gibt alle aktivierten Mailboxen zurück,
     * für die der Benutzer READ besitzt.
     *
     * Rechte mehrerer Gruppen werden zusammengeführt.
     *
     * @return Mailbox[]
     */
    public function getAccessibleMailboxesForUser(
        IUser $user,
    ): array {
        $groupIds =
            $this->groupManager->getUserGroupIds(
                $user
            );

        if ($groupIds === []) {
            return [];
        }

        $permissionsByMailbox =
            $this->accessRuleMapper
                ->findPermissionsByMailboxForGroups(
                    $groupIds
                );

        if (
            $permissionsByMailbox
            === []
        ) {
            return [];
        }

        $mailboxes = [];

        foreach (
            $permissionsByMailbox
            as $mailboxId => $permissions
        ) {
            /*
             * Eine Mailbox darf in der normalen
             * Oberfläche nur erscheinen, wenn der
             * Benutzer sie auch lesen darf.
             */
            if (
                (
                    $permissions
                    & MailboxPermission::READ
                )
                !== MailboxPermission::READ
            ) {
                continue;
            }

            try {
                $mailbox =
                    $this->mailboxMapper->find(
                        (int)$mailboxId
                    );
            } catch (\Throwable) {
                continue;
            }

            /*
             * Deaktivierte Mailboxen niemals
             * an normale Benutzer ausliefern.
             */
            if (!$mailbox->getEnabled()) {
                continue;
            }

            $mailboxes[] =
                $mailbox;
        }

        return $mailboxes;
    }

    /**
     * Effektive Rechte des aktuell
     * angemeldeten Benutzers.
     */
    public function getPermissions(
        int $mailboxId,
    ): int {
        $user =
            $this->userSession->getUser();

        if ($user === null) {
            return 0;
        }

        return $this->getPermissionsForUser(
            $user,
            $mailboxId
        );
    }

    /**
     * Effektive Rechte eines Benutzers.
     *
     * Rechte aller Gruppen werden per OR
     * zusammengeführt.
     */
    public function getPermissionsForUser(
        IUser $user,
        int $mailboxId,
    ): int {
        if ($mailboxId <= 0) {
            return 0;
        }

        $groupIds =
            $this->groupManager->getUserGroupIds(
                $user
            );

        if ($groupIds === []) {
            return 0;
        }

        return $this->accessRuleMapper
            ->getPermissionsForMailboxAndGroups(
                $mailboxId,
                $groupIds
            );
    }

    /**
     * Prüft, ob der aktuell angemeldete Benutzer
     * ein bestimmtes Recht besitzt.
     *
     * Es können auch mehrere Rechte kombiniert
     * übergeben werden.
     */
    public function hasPermission(
        int $mailboxId,
        int $permission,
    ): bool {
        if (
            $mailboxId <= 0
            || $permission <= 0
        ) {
            return false;
        }

        $permissions =
            $this->getPermissions(
                $mailboxId
            );

        return (
            (
                $permissions
                & $permission
            )
            === $permission
        );
    }

    /**
     * Bestehende API-Kompatibilität.
     *
     * "Zugriff" bedeutet ab jetzt:
     * Benutzer besitzt READ.
     */
    public function canAccessMailbox(
        int $mailboxId,
    ): bool {
        return $this->hasPermission(
            $mailboxId,
            MailboxPermission::READ
        );
    }

    /**
     * Gibt eine Mailbox nur zurück, wenn:
     *
     * - sie existiert
     * - sie aktiviert ist
     * - der Benutzer das verlangte Recht besitzt
     *
     * Ohne explizites Recht wird READ verlangt.
     */
    public function getAccessibleMailbox(
        int $mailboxId,
        int $permission = MailboxPermission::READ,
    ): ?Mailbox {
        if (
            !$this->hasPermission(
                $mailboxId,
                $permission
            )
        ) {
            return null;
        }

        try {
            $mailbox =
                $this->mailboxMapper->find(
                    $mailboxId
                );
        } catch (\Throwable) {
            return null;
        }

        if (!$mailbox->getEnabled()) {
            return null;
        }

        return $mailbox;
    }
}