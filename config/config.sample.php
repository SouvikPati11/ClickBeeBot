<?php

/**
 * Sample configuration file.
 *
 * The installer generates the real `config/config.php` from this template.
 * It holds ONLY bootstrap secrets that cannot live in the database (DB
 * credentials, the application key). Every other platform setting is stored
 * in the `settings` table and edited from the Admin Panel — never here.
 *
 * This file must never be committed with real credentials.
 */

declare(strict_types=1);

return [
    // Set to true only after the install wizard finishes successfully.
    'installed' => false,

    'db' => [
        'host'    => 'localhost',
        'name'    => 'clickbee',
        'user'    => 'root',
        'pass'    => '',
        'port'    => 3306,
        'charset' => 'utf8mb4',
    ],

    // 64-char random key used for CSRF/session/signature secrets.
    'app_key' => '',

    // 'production' hides errors from users; 'debug' shows them to admins/logs.
    'environment' => 'production',

    // Absolute public base URL, e.g. https://bot.example.com (no trailing slash).
    'base_url' => '',
];
