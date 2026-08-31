<?php

declare(strict_types=1);

namespace OCA\SharedMail\Settings;

use OCA\SharedMail\AppInfo\Application;
use OCA\SharedMail\Db\AccessRuleMapper;
use OCA\SharedMail\Db\Mailbox;
use OCA\SharedMail\Db\MailboxMapper;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\Settings\ISettings;

class Admin implements ISettings
{
    public function __construct(
        private readonly MailboxMapper $mailboxMapper,
        private readonly AccessRuleMapper $accessRuleMapper,
        private readonly IGroupManager $groupManager,
    ) {
    }

    public function getForm(): TemplateResponse
    {
        $mailboxes = array_map(
            function (Mailbox $mailbox): array {
                $mailboxId = (int)$mailbox->getId();

                /*
                 * Zugeordnete Nextcloud-Gruppen ermitteln.
                 */
                $groupIds = [];

                foreach ($this->accessRuleMapper->findByMailbox($mailboxId) as $rule) {
                    if ($rule->getPrincipalType() !== 'group') {
                        continue;
                    }

                    $groupIds[] = $rule->getPrincipalId();
                }

                return [
                    'id' => $mailboxId,

                    'name' => $mailbox->getName(),
                    'description' => $mailbox->getDescription(),
                    'email' => $mailbox->getEmail(),

                    'imapHost' => $mailbox->getImapHost(),
                    'imapPort' => $mailbox->getImapPort(),
                    'imapSecurity' => $mailbox->getImapSecurity(),
                    'imapUsername' => $mailbox->getImapUsername(),

                    'smtpHost' => $mailbox->getSmtpHost(),
                    'smtpPort' => $mailbox->getSmtpPort(),
                    'smtpSecurity' => $mailbox->getSmtpSecurity(),
                    'smtpUsername' => $mailbox->getSmtpUsername(),

                    /*
                     * Passwörter werden absichtlich NICHT
                     * an den Browser ausgeliefert.
                     */
                    'groupIds' => $groupIds,

                    'enabled' => $mailbox->getEnabled(),
                ];
            },
            $this->mailboxMapper->findAll()
        );

        /*
         * Alle vorhandenen Nextcloud-Gruppen für die Auswahl.
         */
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