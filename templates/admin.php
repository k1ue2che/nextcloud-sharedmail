<?php

declare(strict_types=1);

/** @var array $_ */

script('sharedmail', 'admin');
style('sharedmail', 'admin');

$mailboxes = $_['mailboxes'] ?? [];
$groups = $_['groups'] ?? [];
?>

<div class="section sharedmail-admin">
    <h2>Shared Mail</h2>

    <p>
        Gemeinsame Postfächer für Teams und Organisationen verwalten.
    </p>

    <h3>Postfächer</h3>

    <div id="sharedmail-mailbox-list">

        <?php if ($mailboxes === []): ?>

            <p id="sharedmail-empty">
                Es wurde noch kein gemeinsames Postfach eingerichtet.
            </p>

        <?php else: ?>

            <table class="grid sharedmail-mailbox-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>E-Mail-Adresse</th>
                        <th>Status</th>
                        <th>Aktionen</th>
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
                                        Aktiv
                                    </span>
                                <?php else: ?>
                                    <span class="sharedmail-status sharedmail-status-disabled">
                                        Deaktiviert
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
                                        data-group-ids="<?php p(json_encode($mailbox['groupIds'] ?? [])); ?>">
                                        Bearbeiten
                                    </button>

                                    <button
                                        type="button"
                                        class="sharedmail-delete-mailbox"
                                        data-mailbox-id="<?php p((string)$mailbox['id']); ?>"
                                        data-mailbox-name="<?php p($mailbox['name']); ?>">
                                        Löschen
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
            + Postfach hinzufügen
        </button>
    </div>


    <!--
        Formular zum Anlegen UND Bearbeiten eines Postfachs.
        Die Umschaltung übernimmt admin.js.
    -->
    <div
        id="sharedmail-mailbox-form-wrapper"
        class="sharedmail-form-wrapper"
        style="display:none;">

        <h3 id="sharedmail-form-title">
            Postfach hinzufügen
        </h3>

        <form id="sharedmail-mailbox-form">

            <div class="sharedmail-field">
                <label for="sharedmail-name">
                    <strong>Name</strong>
                </label>

                <input
                    id="sharedmail-name"
                    name="name"
                    type="text"
                    required
                    placeholder="z. B. Vorstand">
            </div>


            <div class="sharedmail-field">
                <label for="sharedmail-description">
                    <strong>Beschreibung</strong>
                </label>

                <textarea
                    id="sharedmail-description"
                    name="description"
                    rows="3"
                    placeholder="Gemeinsames Postfach des Vorstands"></textarea>
            </div>


            <div class="sharedmail-field">
                <label for="sharedmail-email">
                    <strong>E-Mail-Adresse</strong>
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
                    <strong>Zugriffsgruppen</strong>
                </label>

                <p class="sharedmail-hint">
                    Mitglieder dieser Nextcloud-Gruppen können das Postfach
                    sehen und verwenden. Mehrere Gruppen können ausgewählt werden.
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
                            <strong>Sicherheit</strong>
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
                                Keine
                            </option>
                        </select>
                    </div>

                </div>


                <div class="sharedmail-field">
                    <label for="sharedmail-imap-username">
                        <strong>Benutzername</strong>
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
                        <strong>Passwort</strong>
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
                        Leer lassen, um das gespeicherte Passwort beizubehalten.
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
                            <strong>Sicherheit</strong>
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
                                Keine
                            </option>
                        </select>
                    </div>

                </div>


                <div class="sharedmail-field">
                    <label for="sharedmail-smtp-username">
                        <strong>Benutzername</strong>
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
                        <strong>Passwort</strong>
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
                        Leer lassen, um das gespeicherte Passwort beizubehalten.
                    </p>
                </div>
            </div>


            <div class="sharedmail-connection-test">
                <button
                    id="sharedmail-test-connection"
                    type="button">
                    IMAP &amp; SMTP testen
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
                    Postfach speichern
                </button>

                <button
                    id="sharedmail-cancel-mailbox"
                    type="button">
                    Abbrechen
                </button>

            </div>

        </form>
    </div>
</div>