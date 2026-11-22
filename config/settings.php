<?php

declare(strict_types=1);

/**
 * Static settings. Everything the user changes lives in the database.
 *
 * @return array<string, mixed>
 */
return static function (string $rootPath): array {
    $env = getenv('APP_ENV') ?: 'prod';
    $data = $rootPath . '/data';

    return [
        'env' => $env,
        'debug' => $env === 'dev',
        'version' => trim((string) @file_get_contents($rootPath . '/VERSION')),
        'fallback_locale' => 'en',
        'locales' => ['de', 'en'],
        'session' => [
            'name' => 'bioly_session',
            // Without activity the login ends after an hour, in any case 12 hours after logging in.
            'idle_seconds' => 3600,
            'max_seconds' => 12 * 3600,
        ],
        'login' => [
            // Per IP address: a few tries, then a short break.
            'max_failures' => 5,
            'window_seconds' => 10 * 60,
            // Per account, from any address: high enough that a stranger cannot lock
            // the owner out, low enough to stop slow guessing from many addresses.
            'account_max_failures' => 50,
            'account_window_seconds' => 60 * 60,
        ],
        'upload_max_bytes' => 1024 * 1024,
        'paths' => [
            'root' => $rootPath,
            'public' => $rootPath . '/public',
            'data' => $data,
            'database' => $data . '/app.sqlite',
            'config' => $data . '/config.php',
            'cache' => $data . '/cache',
            'logs' => $data . '/logs',
            'uploads' => $data . '/uploads',
            'updates' => $data . '/updates',
            'backups' => $data . '/backups',
            'lang' => $rootPath . '/lang',
            'migrations' => $rootPath . '/migrations',
            'templates' => $rootPath . '/templates',
            'modules' => $rootPath . '/src/Modules',
            'icons' => $rootPath . '/resources/icons',
            'originals' => $rootPath . '/resources/originals',
            'ui_icons' => $rootPath . '/resources/ui-icons',
            'patterns' => $rootPath . '/public/assets/patterns',
        ],
    ];
};
