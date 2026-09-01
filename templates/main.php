<?php

declare(strict_types=1);

/** @var array $_ */

$mailboxes = $_['mailboxes'] ?? [];
?>

<div id="app-content">
    <div class="section">
        <h2>Shared Mail</h2>

        <?php if ($mailboxes === []): ?>

            <p>
                Für dich wurden noch keine gemeinsamen
                Postfächer freigegeben.
            </p>

        <?php else: ?>

            <p>
                Gemeinsame Postfächer, auf die du Zugriff hast:
            </p>

            <div class="sharedmail-user-mailboxes">

                <?php foreach ($mailboxes as $mailbox): ?>

                    <div
                        class="sharedmail-user-mailbox"
                        data-mailbox-id="<?php p((string)$mailbox['id']); ?>">

                        <h3>
                            <?php p($mailbox['name']); ?>
                        </h3>

                        <p>
                            <strong>
                                <?php p($mailbox['email']); ?>
                            </strong>
                        </p>

                        <?php if (!empty($mailbox['description'])): ?>
                            <p>
                                <?php p($mailbox['description']); ?>
                            </p>
                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        <p style="margin-top:30px; opacity:.6;">
            Version 0.2.6 – Development
        </p>
    </div>
</div>