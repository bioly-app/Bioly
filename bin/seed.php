<?php

declare(strict_types=1);

// Development only: admin user, demo content, SMTP to Mailpit. The installer is skipped.

use Bioly\Service\Auth;
use Bioly\Support\Clock;
use Bioly\Theme\Theme;

/** @var Bioly\Services $services */
$services = require __DIR__ . '/bootstrap.php';

if (!$services->settings['debug']) {
    fwrite(STDERR, "Seeding is only allowed with APP_ENV=dev.\n");
    exit(1);
}

$services->ensureDataFolders();
$services->config()->create();
$services->migrator()->migrate();

if ($services->users()->count() > 0) {
    echo "Database already seeded.\n";
    exit(0);
}

$env = static fn(string $name, string $default): string => getenv($name) ?: $default;
$now = Clock::now();

$services->users()->create(
    $env('SEED_ADMIN_USER', 'admin'),
    Auth::hash($env('SEED_ADMIN_PASSWORD', 'Bioly-Demo-2026')),
    $env('SEED_ADMIN_EMAIL', 'admin@bioly.test'),
    'de',
);
$services->users()->update(1, ['email_verified_at' => $now]);

$settings = $services->settingsRepository();
$settings->set('site', [
    'name' => 'Bioly Demo',
    'bio' => 'Hier findest du alles, was es zu finden gibt.',
    'locale' => 'de',
    'timezone' => 'Europe/Berlin',
    'quicklinks' => [
        ['label' => 'E-Mail', 'url' => 'mailto:hallo@bioly.test', 'icon' => 'std:mail'],
        ['label' => 'Website', 'url' => 'https://example.com', 'icon' => 'std:globe'],
    ],
]);
$settings->set('theme', Theme::DEFAULTS);
$settings->set('smtp', [
    'host' => $env('MAIL_HOST', 'mailpit'),
    'port' => (int) $env('MAIL_PORT', '1025'),
    'encryption' => 'none',
    'username' => '',
    'password' => '',
    'from' => 'noreply@bioly.test',
    'verified_at' => $now,
]);
$settings->set('install', ['completed_at' => $now, 'version' => $services->settings['version']]);

$services->pages()->ensure('imprint', 'impressum', 'Impressum');
$services->pages()->ensure('privacy', 'datenschutz', 'Datenschutz');

$link = static fn(string $title, string $subtitle, string $url, string $icon): array => [
    'type' => 'link',
    'is_active' => true,
    'data' => ['title' => $title, 'subtitle' => $subtitle, 'url' => $url, 'icon' => $icon, 'icon_style' => '', 'new_tab' => true],
];

$services->blocks()->replaceAll([
    ['type' => 'divider', 'is_active' => true, 'data' => ['title' => 'Social Media']],
    $link('Instagram', 'instagram.com/bioly', 'https://instagram.com', 'std:camera'),
    $link('Telegram', 't.me/bioly', 'https://t.me', 'std:send'),
    $link('Musik', '', 'https://example.com/music', 'std:music'),
    [
        'type' => 'multilink',
        'is_active' => true,
        'data' => ['title' => 'Weitere Links', 'subtitle' => '2 Links in der Sammlung', 'icon' => 'std:link'],
        'children' => [
            $link('Wunschliste', '', 'https://example.com/wishlist', 'std:gift'),
            $link('Shop', '', 'https://example.com/shop', 'std:shopping-cart'),
        ],
    ],
    ['type' => 'divider', 'is_active' => true, 'data' => ['title' => 'Kontakt']],
    $link('E-Mail', 'hallo@bioly.test', 'mailto:hallo@bioly.test', 'std:mail'),
]);

$services->publisher()->publish();

echo "Seeded admin user and demo content.\n";
