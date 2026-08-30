<?php

declare(strict_types=1);

/** @var array $_ */

script('sharedmail', 'admin');

$mailboxes = $_['mailboxes'] ?? [];
$groups = $_['groups'] ?? [];

script('sharedmail', 'admin');
style('sharedmail', 'admin');
?>

<div class="section">
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

            <table class="grid">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>E-Mail-Adresse</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($mailboxes as $mailbox): ?>
                        <tr>
                            <td><?php p($mailbox['name']); ?></td>
                            <td><?php p($mailbox['email']); ?></td>
                            <td>
                                <?= $mailbox['enabled'] ? 'Aktiv' : 'Deaktiviert' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

    </div>

    <div class="sharedmail-field">
        <label for="sharedmail-group-ids">
            <strong>Zugriffsgruppen</strong>
        </label>

        <p class="sharedmail-hint">
            Mitglieder dieser Nextcloud-Gruppen können das Postfach sehen und verwenden.
            Mehrere Gruppen können ausgewählt werden.
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

    <p style="margin-top: 20px;">
        <button
            id="sharedmail-add-mailbox"
            type="button"
            class="primary">
            + Postfach hinzufügen
        </button>
    </p>

    <div
        id="sharedmail-mailbox-form-wrapper"
        style="display:none; max-width:700px; margin-top:30px;">

        <h3>Postfach hinzufügen</h3>

        <form id="sharedmail-mailbox-form">

            <p>
                <label>
                    Name<br>
                    <input
                        name="name"
                        type="text"
                        required
                        placeholder="z. B. Vorstand">
                </label>
            </p>

            <p>
                <label>
                    Beschreibung<br>
                    <textarea
                        name="description"
                        rows="3"
                        placeholder="Gemeinsames Postfach des Vorstands"></textarea>
                </label>
            </p>

            <p>
                <label>
                    E-Mail-Adresse<br>
                    <input
                        name="email"
                        type="email"
                        required
                        placeholder="vorstand@example.org">
                </label>
            </p>

            <h3>IMAP</h3>

            <p>
                <label>
                    Host<br>
                    <input
                        name="imapHost"
                        type="text"
                        required
                        placeholder="mail.example.org">
                </label>
            </p>

            <p>
                <label>
                    Port<br>
                    <input
                        name="imapPort"
                        type="number"
                        value="993"
                        required>
                </label>
            </p>

            <p>
                <label>
                    Sicherheit<br>
                    <select name="imapSecurity">
                        <option value="ssl">SSL/TLS</option>
                        <option value="tls">STARTTLS</option>
                        <option value="none">Keine</option>
                    </select>
                </label>
            </p>

            <p>
                <label>
                    Benutzername<br>
                    <input
                        name="imapUsername"
                        type="text"
                        required>
                </label>
            </p>

            <p>
                <label>
                    Passwort<br>
                    <input
                        name="imapPassword"
                        type="password"
                        required
                        autocomplete="new-password">
                </label>
            </p>

            <h3>SMTP</h3>

            <p>
                <label>
                    Host<br>
                    <input
                        name="smtpHost"
                        type="text"
                        required
                        placeholder="mail.example.org">
                </label>
            </p>

            <p>
                <label>
                    Port<br>
                    <input
                        name="smtpPort"
                        type="number"
                        value="465"
                        required>
                </label>
            </p>

            <p>
                <label>
                    Sicherheit<br>
                    <select name="smtpSecurity">
                        <option value="ssl">SSL/TLS</option>
                        <option value="tls">STARTTLS</option>
                        <option value="none">Keine</option>
                    </select>
                </label>
            </p>

            <p>
                <label>
                    Benutzername<br>
                    <input
                        name="smtpUsername"
                        type="text"
                        required>
                </label>
            </p>

            <p>
                <label>
                    Passwort<br>
                    <input
                        name="smtpPassword"
                        type="password"
                        required
                        autocomplete="new-password">
                </label>
            </p>

            <p>
                <button
                    type="submit"
                    class="primary">
                    Postfach speichern
                </button>

                <button
                    id="sharedmail-cancel-mailbox"
                    type="button">
                    Abbrechen
                </button>
            </p>

        </form>

    </div>
</div>