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
    ],
];