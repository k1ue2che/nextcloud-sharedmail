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
            'name' => 'admin#deleteMailbox',
            'url' => '/api/mailboxes/{id}',
            'verb' => 'DELETE',
        ],
        [
            'name' => 'admin#updateMailbox',
            'url' => '/api/mailboxes/{id}',
            'verb' => 'PUT',
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
            'name' => 'mail#message',
            'url' => '/api/mailboxes/{id}/messages/{uid}',
            'verb' => 'GET',
        ],
    ],
];