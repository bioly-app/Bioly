<?php

declare(strict_types=1);

namespace Bioly\Tests\Unit;

use Bioly\Theme\Color;
use Bioly\Theme\Theme;
use PHPUnit\Framework\TestCase;

final class ThemeTest extends TestCase
{
    private const PALETTE = ['blau' => '#2563EB', 'grau' => '#52525B', 'gelb' => '#CA8A04'];

    public function testContrastFormula(): void
    {
        self::assertEqualsWithDelta(21.0, Color::contrast('#000000', '#FFFFFF'), 0.01);
        self::assertEqualsWithDelta(1.0, Color::contrast('#777777', '#777777'), 0.01);
    }

    public function testEnsureContrastReachesMinimum(): void
    {
        foreach (['#0E0F11', '#F3F3F1', '#2563EB', '#FDE68A'] as $background) {
            foreach (self::PALETTE as $color) {
                self::assertGreaterThanOrEqual(3.0, Color::contrast(Color::ensureContrast($color, $background, 3.0), $background));
            }
        }
    }

    public function testNormalizeDropsInvalidValues(): void
    {
        $theme = (new Theme(self::PALETTE))->normalize([
            'accent' => 'red',
            'radius' => 99,
            'icon_style' => 'rainbow',
            'header_color' => '#ff0000',
            'header_pattern' => '../../etc/passwd',
            'background' => ['type' => 'image', 'color' => '#ffffff'],
        ]);

        self::assertSame(Theme::DEFAULTS['accent'], $theme['accent']);
        self::assertSame(28, $theme['radius']);
        self::assertSame('tinted', $theme['icon_style']);
        self::assertSame('#FF0000', $theme['header_color']);
        self::assertNull($theme['header_pattern']['id']);
        // An image background without an image falls back to the color.
        self::assertSame('color', $theme['background']['type']);
        self::assertSame('#FFFFFF', $theme['background']['color']);
    }

    public function testTextFollowsBackground(): void
    {
        $theme = new Theme(self::PALETTE);
        $dark = $theme->variables($theme->normalize(['background' => ['color' => '#0E0F11']]));
        $light = $theme->variables($theme->normalize(['background' => ['color' => '#F3F3F1']]));

        self::assertSame('#F4F4F5', $dark['--color-text']);
        self::assertSame('#18181B', $light['--color-text']);
        self::assertGreaterThanOrEqual(4.5, Color::contrast($light['--color-muted'], $light['--color-surface']));
        self::assertArrayHasKey('--icon-gelb', $light);
    }

    public function testCssHasNoUserControlledUrls(): void
    {
        $theme = new Theme(self::PALETTE, ['dots' => ['version' => 'abc123', 'size' => 24]]);
        $css = $theme->css($theme->normalize(['header_pattern' => 'dots']), '/assets/patterns');

        self::assertStringContainsString('url(/assets/patterns/dots.svg?v=abc123)', $css);
        self::assertStringContainsString('.icon--blau', $css);
    }

    public function testHeaderPatternSettings(): void
    {
        $theme = new Theme(self::PALETTE, ['dots' => ['version' => 'abc123', 'size' => 24]]);
        $css = $theme->css($theme->normalize([
            'header_pattern' => ['id' => 'dots', 'strength' => 'strong', 'size' => 'large', 'fade' => 'up'],
        ]), '/assets/patterns');

        self::assertStringContainsString('var(--color-on-header) ' . Theme::PATTERN_STRENGTH['strong'] . '%', $css);
        self::assertStringContainsString('mask-size: 36px, 100% 100%', $css);
        self::assertStringContainsString((string) Theme::PATTERN_FADE['up'], $css);
        self::assertStringContainsString('mask-composite: intersect', $css);

        $plain = $theme->normalize(['header_pattern' => ['id' => 'dots', 'strength' => 'loud', 'size' => '9', 'fade' => 'yes']]);
        self::assertSame(['medium', 'medium', 'none'], [$plain['header_pattern']['strength'], $plain['header_pattern']['size'], $plain['header_pattern']['fade']]);
    }

    public function testLegacyPatternSettingsAreMigrated(): void
    {
        $theme = new Theme(self::PALETTE, ['dots' => ['version' => 'abc123', 'size' => 24], 'waves' => ['version' => 'x', 'size' => 40]]);
        $normalized = $theme->normalize([
            'header_pattern' => 'dots',
            'header_pattern_strength' => 'subtle',
            'header_pattern_size' => 'small',
            'header_pattern_fade' => true,
            'background' => ['type' => 'pattern', 'color' => '#101010', 'pattern' => 'waves'],
        ]);

        self::assertSame(['id' => 'dots', 'strength' => 'subtle', 'size' => 'small', 'fade' => 'down'], $normalized['header_pattern']);
        self::assertSame('color', $normalized['background']['type']);
        self::assertSame('waves', $normalized['background']['pattern']['id']);
        self::assertArrayNotHasKey('header_pattern_strength', $normalized);
    }

    public function testBackgroundPatternUsesSameSettings(): void
    {
        $theme = new Theme(self::PALETTE, ['dots' => ['version' => 'abc123', 'size' => 24]]);
        $css = $theme->css($theme->normalize([
            'background' => ['type' => 'color', 'color' => '#101010', 'pattern' => ['id' => 'dots', 'strength' => 'subtle', 'size' => 'small', 'fade' => 'none']],
        ]), '/assets/patterns');

        self::assertStringContainsString('.site__pattern { background-color: color-mix(in srgb, var(--color-on-bg) ' . Theme::PATTERN_STRENGTH['subtle'] . '%', $css);
        self::assertStringContainsString('mask-size: 18px;', $css);
    }

    public function testBackgroundImage(): void
    {
        $theme = new Theme(self::PALETTE);
        $normalized = $theme->normalize([
            'background' => ['type' => 'image', 'color' => '#101010', 'image' => 'image-0123456789abcdef.jpg', 'image_tone' => '#f0e0d0'],
        ]);

        self::assertSame('image', $normalized['background']['type']);
        self::assertSame('#F0E0D0', $normalized['background']['image_tone']);
        self::assertStringContainsString('.site__image { background-image: url(/media/image-0123456789abcdef.jpg); }', $theme->css($normalized, '/assets/patterns'));
        // Text and cards follow the tone of the image, not the hidden color.
        self::assertSame('#18181B', $theme->variables($normalized)['--color-text']);

        $evil = $theme->normalize(['background' => ['type' => 'image', 'image' => '../x.jpg); background: url(//evil', 'image_tone' => 'red']]);
        self::assertSame('color', $evil['background']['type']);
        self::assertNull($evil['background']['image']);
        self::assertNull($evil['background']['image_tone']);
    }

    public function testBrandColorIsTheSameEverywhere(): void
    {
        $root = dirname(__DIR__, 2);
        $backend = (string) file_get_contents($root . '/public/assets/css/backend/tokens.css');
        $site = (string) file_get_contents($root . '/public/assets/css/site.css');

        self::assertSame(Theme::BRAND, Theme::DEFAULTS['accent']);
        self::assertStringContainsString('--brand: ' . strtolower(Theme::BRAND) . ';', $backend);
        self::assertStringContainsString('--color-accent: ' . strtolower(Theme::BRAND) . ';', $site);
        self::assertGreaterThanOrEqual(4.5, Color::contrast(Theme::BRAND, '#FFFFFF'));
    }

    public function testWarnsAboutLowHeadingContrast(): void
    {
        $theme = new Theme(self::PALETTE);
        $warnings = $theme->warnings($theme->normalize(['heading_color' => '#111111', 'background' => ['color' => '#0E0F11']]));

        self::assertSame('heading_color', $warnings[0]['field']);
    }
}
