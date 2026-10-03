<?php

declare(strict_types=1);

use OCA\SharedMail\Service\MailboxPermission;

/** @var array $_ */

script('sharedmail', 'admin');
style('sharedmail', 'admin');

$mailboxes = $_['mailboxes'] ?? [];
$groups = $_['groups'] ?? [];
?>

<div class="section sharedmail-admin">
    <h2>Shared Mail</h2>

    <p>
        <?php p($l->t('Manage shared mailboxes for teams and organizations.')); ?>
    </p>

    <h3><?php p($l->t('Mailboxes')); ?></h3>

    <div id="sharedmail-mailbox-list">

        <?php if ($mailboxes === []): ?>

            <p id="sharedmail-empty">
                <?php p($l->t('No shared mailbox has been configured yet.')); ?>
            </p>

        <?php else: ?>

            <table class="grid sharedmail-mailbox-table">
                <thead>
                    <tr>
                        <th><?php p($l->t('Name')); ?></th>
                        <th><?php p($l->t('Email address')); ?></th>
                        <th><?php p($l->t('Status')); ?></th>
                        <th><?php p($l->t('Actions')); ?></th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($mailboxes as $mailbox): ?>
                        <tr>
                            <td>
                                <strong>
                                    <?php p($mailbox['name']); ?>
                                </strong>

                                <?php if (!empty($mailbox['description'])): ?>
                                    <div class="sharedmail-mailbox-description">
                                        <?php p($mailbox['description']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php p($mailbox['email']); ?>
                            </td>

                            <td>
                                <?php if ($mailbox['enabled']): ?>
                                    <span class="sharedmail-status sharedmail-status-active">
                                        <?php p($l->t('Active')); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="sharedmail-status sharedmail-status-disabled">
                                        <?php p($l->t('Disabled')); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="sharedmail-actions">

                                    <button
                                        type="button"
                                        class="sharedmail-edit-mailbox"
                                        data-mailbox-id="<?php p((string)$mailbox['id']); ?>"
                                        data-name="<?php p($mailbox['name']); ?>"
                                        data-description="<?php p((string)($mailbox['description'] ?? '')); ?>"
                                        data-email="<?php p($mailbox['email']); ?>"
                                        data-imap-host="<?php p($mailbox['imapHost']); ?>"
                                        data-imap-port="<?php p((string)$mailbox['imapPort']); ?>"
                                        data-imap-security="<?php p($mailbox['imapSecurity']); ?>"
                                        data-imap-username="<?php p($mailbox['imapUsername']); ?>"
                                        data-smtp-host="<?php p($mailbox['smtpHost']); ?>"
                                        data-smtp-port="<?php p((string)$mailbox['smtpPort']); ?>"
                                        data-smtp-security="<?php p($mailbox['smtpSecurity']); ?>"
                                        data-smtp-username="<?php p($mailbox['smtpUsername']); ?>"
                                        data-group-ids="<?php p(json_encode($mailbox['groupIds'] ?? [])); ?>"
                                        data-group-permissions="<?php p(json_encode($mailbox['groupPermissions'] ?? [])); ?>">
                                        <?php p($l->t('Edit')); ?>
                                    </button>

                                    <button
                                        type="button"
                                        class="sharedmail-delete-mailbox"
                                        data-mailbox-id="<?php p((string)$mailbox['id']); ?>"
                                        data-mailbox-name="<?php p($mailbox['name']); ?>">
                                        <?php p($l->t('Delete')); ?>
                                    </button>

                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

    </div>

    <div class="sharedmail-add-wrapper">
        <button
            id="sharedmail-add-mailbox"
            type="button"
            class="primary">
            + <?php p($l->t('Add mailbox')); ?>
        </button>
    </div>

    <div
        id="sharedmail-mailbox-form-wrapper"
        class="sharedmail-form-wrapper"
        style="display:none;">

        <h3 id="sharedmail-form-title">
            <?php p($l->t('Add mailbox')); ?>
        </h3>

        <form id="sharedmail-mailbox-form">

            <div class="sharedmail-field">
                <label for="sharedmail-name">
                    <strong><?php p($l->t('Name')); ?></strong>
                </label>

                <input
                    id="sharedmail-name"
                    name="name"
                    type="text"
                    required
                    placeholder="<?php p($l->t('e.g. Board')); ?>">
            </div>

            <div class="sharedmail-field">
                <label for="sharedmail-description">
                    <strong><?php p($l->t('Description')); ?></strong>
                </label>

                <textarea
                    id="sharedmail-description"
                    name="description"
                    rows="3"
                    placeholder="<?php p($l->t('Shared mailbox of the board')); ?>"></textarea>
            </div>

            <div class="sharedmail-field">
                <label for="sharedmail-email">
                    <strong><?php p($l->t('Email address')); ?></strong>
                </label>

                <input
                    id="sharedmail-email"
                    name="email"
                    type="email"
                    required
                    placeholder="vorstand@example.org">
            </div>

            <div class="sharedmail-field">
                <label for="sharedmail-group-ids">
                    <strong><?php p($l->t('Access groups')); ?></strong>
                </label>

                <p class="sharedmail-hint">
                    <?php p($l->t('Members of these Nextcloud groups can view and use the mailbox. Multiple groups can be selected.')); ?>
                </p>

                <select
                    id="sharedmail-group-ids"
                    name="groupIds[]"
                    multiple
                    size="8"
                    required>

                    <?php foreach ($groups as $group): ?>
                        <option value="<?php p($group['id']); ?>">
                            <?php p($group['name']); ?>
                            (<?php p($group['id']); ?>)
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div
                id="sharedmail-group-permissions"
                class="sharedmail-group-permissions"
                data-read="<?php p((string)MailboxPermission::READ); ?>"
                data-reply="<?php p((string)MailboxPermission::REPLY); ?>"
                data-compose="<?php p((string)MailboxPermission::COMPOSE); ?>"
                data-move="<?php p((string)MailboxPermission::MOVE); ?>"
                data-delete="<?php p((string)MailboxPermission::DELETE); ?>"
                data-assign="<?php p((string)MailboxPermission::ASSIGN); ?>"
                data-change-status="<?php p((string)MailboxPermission::CHANGE_STATUS); ?>"
                data-manage="<?php p((string)MailboxPermission::MANAGE); ?>"
                data-default="<?php p((string)MailboxPermission::DEFAULT); ?>"
                style="display:none;">

                <h3><?php p($l->t('Group permissions')); ?></h3>

                <p class="sharedmail-hint">
                    <?php p($l->t('Permissions apply to the selected Nextcloud group. Read access is always required for every access group.')); ?>
                </p>

                <div id="sharedmail-group-permissions-list"></div>
            </div>

            <div class="sharedmail-form-section">
                <h3>IMAP</h3>

                <div class="sharedmail-field">
                    <label for="sharedmail-imap-host">
                        <strong>Host</strong>
                    </label>

                    <input
                        id="sharedmail-imap-host"
                        name="imapHost"
                        type="text"
                        required
                        placeholder="mail.example.org">
                </div>

                <div class="sharedmail-field-row">

                    <div class="sharedmail-field sharedmail-field-port">
                        <label for="sharedmail-imap-port">
                            <strong>Port</strong>
                        </label>

                        <input
                            id="sharedmail-imap-port"
                            name="imapPort"
                            type="number"
                            min="1"
                            max="65535"
                            value="993"
                            required>
                    </div>

                    <div class="sharedmail-field sharedmail-field-security">
                        <label for="sharedmail-imap-security">
                            <strong><?php p($l->t('Security')); ?></strong>
                        </label>

                        <select
                            id="sharedmail-imap-security"
                            name="imapSecurity">

                            <option value="ssl">
                                SSL/TLS
                            </option>

                            <option value="tls">
                                STARTTLS
                            </option>

                            <option value="none">
                                <?php p($l->t('None')); ?>
                            </option>
                        </select>
                    </div>

                </div>

                <div class="sharedmail-field">
                    <label for="sharedmail-imap-username">
                        <strong><?php p($l->t('Username')); ?></strong>
                    </label>

                    <input
                        id="sharedmail-imap-username"
                        name="imapUsername"
                        type="text"
                        required
                        autocomplete="off">
                </div>

                <div class="sharedmail-field">
                    <label for="sharedmail-imap-password">
                        <strong><?php p($l->t('Password')); ?></strong>
                    </label>

                    <input
                        id="sharedmail-imap-password"
                        name="imapPassword"
                        type="password"
                        required
                        autocomplete="new-password">

                    <p
                        id="sharedmail-imap-password-hint"
                        class="sharedmail-hint sharedmail-edit-password-hint"
                        style="display:none;">
                        <?php p($l->t('Leave blank to keep the stored password.')); ?>
                    </p>
                </div>
            </div>

            <div class="sharedmail-form-section">
                <h3>SMTP</h3>

                <div class="sharedmail-field">
                    <label for="sharedmail-smtp-host">
                        <strong>Host</strong>
                    </label>

                    <input
                        id="sharedmail-smtp-host"
                        name="smtpHost"
                        type="text"
                        required
                        placeholder="mail.example.org">
                </div>

                <div class="sharedmail-field-row">

                    <div class="sharedmail-field sharedmail-field-port">
                        <label for="sharedmail-smtp-port">
                            <strong>Port</strong>
                        </label>

                        <input
                            id="sharedmail-smtp-port"
                            name="smtpPort"
                            type="number"
                            min="1"
                            max="65535"
                            value="465"
                            required>
                    </div>

                    <div class="sharedmail-field sharedmail-field-security">
                        <label for="sharedmail-smtp-security">
                            <strong><?php p($l->t('Security')); ?></strong>
                        </label>

                        <select
                            id="sharedmail-smtp-security"
                            name="smtpSecurity">

                            <option value="ssl">
                                SSL/TLS
                            </option>

                            <option value="tls">
                                STARTTLS
                            </option>

                            <option value="none">
                                <?php p($l->t('None')); ?>
                            </option>
                        </select>
                    </div>

                </div>

                <div class="sharedmail-field">
                    <label for="sharedmail-smtp-username">
                        <strong><?php p($l->t('Username')); ?></strong>
                    </label>

                    <input
                        id="sharedmail-smtp-username"
                        name="smtpUsername"
                        type="text"
                        required
                        autocomplete="off">
                </div>

                <div class="sharedmail-field">
                    <label for="sharedmail-smtp-password">
                        <strong><?php p($l->t('Password')); ?></strong>
                    </label>

                    <input
                        id="sharedmail-smtp-password"
                        name="smtpPassword"
                        type="password"
                        required
                        autocomplete="new-password">

                    <p
                        id="sharedmail-smtp-password-hint"
                        class="sharedmail-hint sharedmail-edit-password-hint"
                        style="display:none;">
                        <?php p($l->t('Leave blank to keep the stored password.')); ?>
                    </p>
                </div>
            </div>

            <div class="sharedmail-connection-test">
                <button
                    id="sharedmail-test-connection"
                    type="button">
                    <?php p($l->t('Test IMAP & SMTP')); ?>
                </button>

                <div
                    id="sharedmail-connection-result"
                    class="sharedmail-connection-result"
                    style="display:none;">
                </div>
            </div>

            <div class="sharedmail-form-actions">

                <button
                    id="sharedmail-save-mailbox"
                    type="submit"
                    class="primary">
                    <?php p($l->t('Save mailbox')); ?>
                </button>

                <button
                    id="sharedmail-cancel-mailbox"
                    type="button">
                    <?php p($l->t('Cancel')); ?>
                </button>

            </div>

        </form>
    </div>
</div>