<?php

declare(strict_types=1);

return [
    'routes' => [
        [
            'name' => 'page#index',
            'url' => '/',
            'verb' => 'GET',
        ],

        [
            'name' => 'admin#createMailbox',
            'url' => '/api/mailboxes',
            'verb' => 'POST',
        ],

        [
            'name' => 'admin#testConnection',
            'url' => '/api/mailboxes/test',
            'verb' => 'POST',
        ],

        [
            'name' => 'admin#updateMailbox',
            'url' => '/api/mailboxes/{id}',
            'verb' => 'PUT',
        ],

        [
            'name' => 'admin#deleteMailbox',
            'url' => '/api/mailboxes/{id}',
            'verb' => 'DELETE',
        ],

        [
            'name' => 'mail#folders',
            'url' => '/api/mailboxes/{id}/folders',
            'verb' => 'GET',
        ],

        [
            'name' => 'mail#messages',
            'url' => '/api/mailboxes/{id}/messages',
            'verb' => 'GET',
        ],

        [
            'name' => 'mail#markRead',
            'url' => '/api/mailboxes/{id}/messages/{uid}/read',
            'verb' => 'POST',
        ],

        [
            'name' => 'mail#markUnread',
            'url' => '/api/mailboxes/{id}/messages/{uid}/unread',
            'verb' => 'POST',
        ],

        [
            'name' => 'move#message',
            'url' => '/api/mailboxes/{id}/messages/{uid}/move',
            'verb' => 'POST',
        ],

        [
            'name' => 'reply#send',
            'url' => '/api/mailboxes/{id}/messages/{uid}/reply',
            'verb' => 'POST',
        ],

        [
            'name' => 'attachment#view',
            'url' => '/api/mailboxes/{id}/messages/{uid}/attachment/view',
            'verb' => 'GET',
        ],

        [
            'name' => 'attachment#download',
            'url' => '/api/mailboxes/{id}/messages/{uid}/attachment',
            'verb' => 'GET',
        ],

        [
            'name' => 'mail#message',
            'url' => '/api/mailboxes/{id}/messages/{uid}',
            'verb' => 'GET',
        ],

        [
            'name' => 'compose#send',
            'url' => '/api/mailboxes/{id}/compose',
            'verb' => 'POST',
        ],
    ],
];