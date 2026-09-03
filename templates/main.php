<?php

declare(strict_types=1);

/** @var array $_ */

script('sharedmail', 'composer-editor');
script('sharedmail', 'main');
script('sharedmail', 'new-message');

style('sharedmail', 'main');
style('sharedmail', 'composer');

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

                    <div
                        class="sharedmail-mailbox-section"
                        data-mailbox-section-id="<?php p((string)$mailbox['id']); ?>">

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

                        <div
                            class="sharedmail-mailbox-folder-host"
                            data-folder-host-for="<?php p((string)$mailbox['id']); ?>"
                            <?php if ($index !== 0): ?>
                                hidden
                            <?php endif; ?>>

                            <div
                                class="sharedmail-folder-loading"
                                hidden>
                                IMAP-Ordner werden geladen …
                            </div>

                            <div
                                class="sharedmail-folder-error"
                                hidden>
                            </div>

                            <div
                                class="sharedmail-folder-list">
                            </div>

                        </div>

                    </div>

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
                id="sharedmail-message-area"
                class="sharedmail-message-area">

                <p>
                    Postfach wird geladen …
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