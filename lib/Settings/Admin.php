<?php

declare(strict_types=1);

namespace OCA\SharedMail\Settings;

use OCA\SharedMail\AppInfo\Application;
use OCA\SharedMail\Db\Mailbox;
use OCA\SharedMail\Db\MailboxMapper;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\IGroupManager;

class Admin implements ISettings
{
    public function __construct(
        private readonly MailboxMapper $mailboxMapper,
    ) {
    }

    public function getForm(): TemplateResponse
    {
        $mailboxes = array_map(
            static fn (Mailbox $mailbox): array => [
                'id' => $mailbox->getId(),
                'name' => $mailbox->getName(),
                'email' => $mailbox->getEmail(),
                'enabled' => $mailbox->getEnabled(),
            ],
            $this->mailboxMapper->findAll()
        );
        $groups = array_map(
            static fn ($group): array => [
                'id' => $group->getGID(),
                'name' => $group->getDisplayName(),
            ],
            $this->groupManager->search('')
        );

        usort(
            $groups,
            static fn (array $a, array $b): int =>
                strcasecmp($a['name'], $b['name'])
        );

        return new TemplateResponse(
            Application::APP_ID,
            'admin',
            [
                'mailboxes' => $mailboxes,
                'groups' => $groups,
            ],
            ''
        );
    }

    public function getSection(): string
    {
        return Application::APP_ID;
    }

    public function getPriority(): int
    {
        return 10;
    }
}