<?php

declare(strict_types=1);

namespace Bioly\Tests\Unit;

use Bioly\Icon\IconRegistry;
use Bioly\Icon\UiIcons;
use PHPUnit\Framework\TestCase;

final class IconRegistryTest extends TestCase
{
    public function testReadsColorFolders(): void
    {
        $root = dirname(__DIR__, 2);
        $registry = new IconRegistry($root . '/resources/icons', $root . '/resources/originals', sys_get_temp_dir() . '/bioly-icons-' . bin2hex(random_bytes(3)) . '.php');

        self::assertSame('rot', $registry->get('std:heart')['color'] ?? null);
        self::assertStringContainsString('aria-hidden="true"', $registry->html('std:heart'));
        self::assertSame([], $registry->problems());
    }

    public function testBrandLogosComeInTwoVersions(): void
    {
        $base = sys_get_temp_dir() . '/bioly-brands-' . bin2hex(random_bytes(3));
        mkdir($base . '/icons/blau', 0o777, true);
        mkdir($base . '/originals');
        file_put_contents($base . '/icons/colors.json', '{"blau": "#2563EB"}');
        $path = '<path d="M0 0h24v24H0z"/>';
        file_put_contents($base . '/icons/blau/mastodon.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">' . $path . '</svg>');
        file_put_contents($base . '/icons/blau/globe.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">' . $path . '</svg>');
        file_put_contents($base . '/originals/mastodon.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#6364FF">' . $path . '</svg>');
        file_put_contents($base . '/originals/brands.json', '{"icons": {"mastodon": {"title": "Mastodon", "hex": "6364FF", "folder": "blau"}}}');

        $registry = new IconRegistry($base . '/icons', $base . '/originals', $base . '/cache.php');
        $listing = [];

        foreach ($registry->listing(new \Bioly\I18n\Translator([], 'de', 'de')) as $icon) {
            $listing[$icon['id']] = $icon;
        }

        self::assertTrue($listing['std:mastodon']['brand']);
        self::assertSame('blau', $listing['std:mastodon']['color']);
        self::assertSame('Mastodon', $listing['std:mastodon']['label']);
        self::assertTrue($listing['orig:mastodon']['brand']);
        self::assertNull($listing['orig:mastodon']['color']);
        self::assertStringContainsString('icon--original', $registry->html('orig:mastodon'));
        self::assertFalse($listing['std:globe']['brand']);
    }

    public function testRejectsUnsafeSvg(): void
    {
        self::assertNull(IconRegistry::clean('<svg viewBox="0 0 24 24"><script>alert(1)</script></svg>'));
        self::assertNull(IconRegistry::clean('<svg viewBox="0 0 24 24" onload="x()"></svg>'));
        self::assertNull(IconRegistry::clean('<svg viewBox="0 0 24 24"><image href="x.png"/></svg>'));
        self::assertNull(IconRegistry::clean('<svg><path d="M0 0"/></svg>'));
    }

    public function testStripsSizeAndComments(): void
    {
        $svg = IconRegistry::clean("<?xml version=\"1.0\"?>\n<!-- c --><svg width=\"24\" height=\"24\" class=\"x\" viewBox=\"0 0 24 24\"><path d=\"M0 0\"/></svg>");

        self::assertSame('<svg viewBox="0 0 24 24"><path d="M0 0"/></svg>', $svg);
    }

    public function testUiIconsAreLucideAndClean(): void
    {
        $icons = new UiIcons(dirname(__DIR__, 2) . '/resources/ui-icons');
        $html = $icons->html('x', 'nav__icon');

        self::assertStringStartsWith('<svg class="nav__icon" aria-hidden="true"', $html);
        self::assertStringNotContainsString('<!--', $html);
        self::assertSame('', $icons->html('../secret'));
        self::assertSame('', $icons->html('does-not-exist'));
    }
}
