<?php

declare(strict_types=1);

/** @var array $_ */

$mailboxes = $_['mailboxes'] ?? [];
?>

<div class="section">
    <h2>Shared Mail</h2>

    <p>
        Gemeinsame Postfächer für Teams und Organisationen verwalten.
    </p>

    <h3>Postfächer</h3>

    <?php if ($mailboxes === []): ?>
        <p>
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
                            <?php if ($mailbox['enabled']): ?>
                                Aktiv
                            <?php else: ?>
                                Deaktiviert
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

    <p style="margin-top: 20px;">
        <button type="button" class="primary" disabled>
            + Postfach hinzufügen
        </button>
    </p>
</div>