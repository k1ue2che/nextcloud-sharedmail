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
        $user = $this->userSession->getUser();

        if ($user === null) {
            return [];
        }

        return $this->getAccessibleMailboxesForUser(
            $user
        );
    }

    /**
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

        $mailboxIds =
            $this->accessRuleMapper
                ->findMailboxIdsForGroups(
                    $groupIds
                );

        if ($mailboxIds === []) {
            return [];
        }

        $mailboxes = [];

        foreach ($mailboxIds as $mailboxId) {
            try {
                $mailbox =
                    $this->mailboxMapper->find(
                        $mailboxId
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

            $mailboxes[] = $mailbox;
        }

        return $mailboxes;
    }

    public function canAccessMailbox(
        int $mailboxId,
    ): bool {
        $user = $this->userSession->getUser();

        if ($user === null) {
            return false;
        }

        $groupIds =
            $this->groupManager->getUserGroupIds(
                $user
            );

        if ($groupIds === []) {
            return false;
        }

        foreach ($groupIds as $groupId) {
            if (
                $this->accessRuleMapper
                    ->groupHasAccess(
                        $mailboxId,
                        $groupId
                    )
            ) {
                return true;
            }
        }

        return false;
    }

    public function getAccessibleMailbox(
        int $mailboxId,
    ): ?Mailbox {
        if (!$this->canAccessMailbox($mailboxId)) {
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