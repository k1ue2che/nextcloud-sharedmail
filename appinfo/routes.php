<?php

declare(strict_types=1);

return [
    'routes' => [

        /*
         * Hauptseite
         */
        [
            'name' => 'page#index',
            'url' => '/',
            'verb' => 'GET',
        ],


        /*
         * Administration
         */
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


        /*
         * Shared-Mail Client
         */
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


        /*
         * Persönlicher Lesestatus.
         *
         * Diese spezifischen Routen stehen bewusst
         * vor der allgemeinen Message-Route.
         */
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


        /*
         * Einzelne Nachricht
         */
        [
            'name' => 'mail#message',
            'url' => '/api/mailboxes/{id}/messages/{uid}',
            'verb' => 'GET',
        ],
    ],
];