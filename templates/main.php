<?php

declare(strict_types=1);

/** @var array $_ */

script('sharedmail', 'main');
style('sharedmail', 'main');

$mailboxes = $_['mailboxes'] ?? [];
?>

<div id="app-content" class="sharedmail-app">

    <aside class="sharedmail-sidebar">

        <div class="sharedmail-sidebar-header">
            <h2>Shared Mail</h2>
        </div>

        <?php if ($mailboxes === []): ?>

            <div class="sharedmail-empty">
                <p>
                    Für dich wurden noch keine gemeinsamen
                    Postfächer freigegeben.
                </p>
            </div>

        <?php else: ?>

            <div class="sharedmail-mailbox-list">

                <?php foreach ($mailboxes as $index => $mailbox): ?>

                    <button
                        type="button"
                        class="sharedmail-mailbox-button<?php
                            echo $index === 0 ? ' active' : '';
                        ?>"
                        data-mailbox-id="<?php p((string)$mailbox['id']); ?>"
                        data-mailbox-name="<?php p($mailbox['name']); ?>"
                        data-mailbox-email="<?php p($mailbox['email']); ?>">

                        <span class="sharedmail-mailbox-name">
                            <?php p($mailbox['name']); ?>
                        </span>

                        <span class="sharedmail-mailbox-email">
                            <?php p($mailbox['email']); ?>
                        </span>

                    </button>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </aside>


    <main class="sharedmail-main">

        <?php if ($mailboxes !== []): ?>

            <header class="sharedmail-main-header">

                <div>
                    <h2 id="sharedmail-current-mailbox-name">
                        <?php p($mailboxes[0]['name']); ?>
                    </h2>

                    <div
                        id="sharedmail-current-mailbox-email"
                        class="sharedmail-current-email">

                        <?php p($mailboxes[0]['email']); ?>

                    </div>
                </div>

            </header>


            <div
                id="sharedmail-folder-loading"
                class="sharedmail-loading">

                IMAP-Ordner werden geladen …

            </div>


            <div
                id="sharedmail-folder-error"
                class="sharedmail-error"
                style="display:none;">
            </div>


            <div
                id="sharedmail-folder-list"
                class="sharedmail-folder-list">
            </div>


            <div
                id="sharedmail-message-area"
                class="sharedmail-message-area">

                <p>
                    Wähle einen Ordner aus.
                </p>

            </div>

        <?php else: ?>

            <div class="sharedmail-main-empty">

                <h2>Shared Mail</h2>

                <p>
                    Sobald dir ein gemeinsames Postfach freigegeben wird,
                    erscheint es hier.
                </p>

            </div>

        <?php endif; ?>

    </main>

</div>