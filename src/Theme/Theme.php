<?php

declare(strict_types=1);

namespace Bioly\Theme;

use Bioly\Service\MediaService;

/**
 * Turns the stored design settings into CSS custom properties.
 * Light or dark is never chosen: it follows from the background color.
 */
final class Theme
{
    public const ICON_STYLES = ['tinted', 'accent', 'mono'];

    /** Opacity of a pattern in percent (header and background). */
    public const PATTERN_STRENGTH = ['subtle' => 8, 'medium' => 18, 'strong' => 32];

    /** Zoom of a pattern tile; whole tiles stay seamless at every size. */
    public const PATTERN_SIZE = ['small' => 0.75, 'medium' => 1.0, 'large' => 1.5];

    /** "Fade out towards the bottom": a second mask layer, from full at the top to nothing at 80 %. */
    /** Fade out towards the bottom or the top: second mask layer, intersected with the pattern */
    public const PATTERN_FADE = ['none' => null, 'down' => 'linear-gradient(#000, transparent 80%)', 'up' => 'linear-gradient(to top, #000, transparent 80%)'];

    /** A pattern choice: which tile, how strong, how big, fading out or not. */
    private const NO_PATTERN = ['id' => null, 'strength' => 'medium', 'size' => 'medium', 'fade' => 'none'];

    /**
     * Bioly's brand color "Azur". Default main color of a new page. The backend uses the
     * same value as --brand in public/assets/css/backend/tokens.css (a test keeps both equal).
     */
    public const BRAND = '#0B63B8';

    /** @var array<string, mixed> */
    public const DEFAULTS = [
        'accent' => self::BRAND,
        'header_color' => null,
        'heading_color' => null,
        'header_pattern' => self::NO_PATTERN,
        // type "color" (with an optional pattern) or "image"; image_tone is the image's average
        // color, so footer and dividers on top of the photo still get the right contrast.
        'background' => ['type' => 'color', 'color' => '#0E0F11', 'pattern' => self::NO_PATTERN, 'image' => null, 'image_tone' => null],
        'icon_style' => 'tinted',
        'radius' => 20,
        'favicon' => ['type' => 'auto', 'icon' => null, 'file' => null],
    ];

    /**
     * @param array<string, string> $palette name => hex (resources/icons/colors.json)
     * @param array<string, array{version: string, size: int}> $patterns available patterns by id: file version and tile size in px
     */
    public function __construct(
        private readonly array $palette,
        private readonly array $patterns = [],
    ) {}

    /**
     * Cleans user input; unknown or invalid values fall back to the defaults.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function normalize(array $input): array
    {
        $d = self::DEFAULTS;
        $background = is_array($input['background'] ?? null) ? $input['background'] : [];
        $image = is_string($background['image'] ?? null) && MediaService::validName($background['image'], ['image', 'avatar']) ? $background['image'] : null;
        // Before 0.1.6 the header pattern was a plain name with its settings beside it.
        $headerPattern = is_array($input['header_pattern'] ?? null) ? $input['header_pattern'] : [
            'id' => $input['header_pattern'] ?? null,
            'strength' => $input['header_pattern_strength'] ?? null,
            'size' => $input['header_pattern_size'] ?? null,
            'fade' => $input['header_pattern_fade'] ?? null,
        ];

        return [
            'accent' => Color::isHex($input['accent'] ?? null) ? strtoupper($input['accent']) : $d['accent'],
            'header_color' => Color::isHex($input['header_color'] ?? null) ? strtoupper($input['header_color']) : null,
            'heading_color' => Color::isHex($input['heading_color'] ?? null) ? strtoupper($input['heading_color']) : null,
            'header_pattern' => $this->normalizePattern($headerPattern),
            'background' => [
                'type' => ($background['type'] ?? null) === 'image' && $image !== null ? 'image' : 'color',
                'color' => Color::isHex($background['color'] ?? null) ? strtoupper($background['color']) : $d['background']['color'],
                // Before 0.1.6: type "pattern" with the name in "pattern".
                'pattern' => $this->normalizePattern(is_array($background['pattern'] ?? null) ? $background['pattern'] : ['id' => $background['pattern'] ?? null]),
                'image' => $image,
                'image_tone' => Color::isHex($background['image_tone'] ?? null) ? strtoupper($background['image_tone']) : null,
            ],
            'icon_style' => in_array($input['icon_style'] ?? null, self::ICON_STYLES, true) ? $input['icon_style'] : $d['icon_style'],
            'radius' => max(6, min(28, (int) ($input['radius'] ?? $d['radius']))),
            'favicon' => self::normalizeFavicon(is_array($input['favicon'] ?? null) ? $input['favicon'] : []),
        ];
    }

    /**
     * One pattern choice, the same for header and background.
     *
     * @param array<string, mixed> $input
     * @return array{id: ?string, strength: string, size: string, fade: string}
     */
    private function normalizePattern(array $input): array
    {
        $id = $input['id'] ?? null;

        return [
            'id' => is_string($id) && isset($this->patterns[$id]) ? $id : null,
            'strength' => isset(self::PATTERN_STRENGTH[$input['strength'] ?? '']) ? $input['strength'] : self::NO_PATTERN['strength'],
            'size' => isset(self::PATTERN_SIZE[$input['size'] ?? '']) ? $input['size'] : self::NO_PATTERN['size'],
            // Before 0.1.6 fade was true/false and only went down.
            'fade' => match (true) {
                ($input['fade'] ?? null) === true => 'down',
                is_string($input['fade'] ?? null) && array_key_exists($input['fade'], self::PATTERN_FADE) => $input['fade'],
                default => 'none',
            },
        ];
    }

    /**
     * Favicon choice: "auto" (first letter), "icon" (library icon in the look of the icon style)
     * or "upload" (own SVG from the media library, icon-*.svg; older favicon-*.svg still work). Whether icon or file exist is checked when rendering.
     *
     * @param array<string, mixed> $input
     * @return array{type: string, icon: ?string, file: ?string}
     */
    private static function normalizeFavicon(array $input): array
    {
        $icon = is_string($input['icon'] ?? null) && preg_match('/^(std:[a-z0-9-]+|own:[a-f0-9]{16})$/', $input['icon']) === 1 ? $input['icon'] : null;
        $file = is_string($input['file'] ?? null) && MediaService::validName($input['file'], ['icon', 'favicon']) ? $input['file'] : null;
        $type = in_array($input['type'] ?? null, ['auto', 'icon', 'upload'], true) ? $input['type'] : 'auto';

        if (($type === 'icon' && $icon === null) || ($type === 'upload' && $file === null)) {
            $type = 'auto';
        }

        return [
            'type' => $type,
            'icon' => $icon,
            'file' => $file,
        ];
    }

    /**
     * @param array<string, mixed> $theme normalized theme
     * @return array<string, string> CSS variable name => value
     */
    public function variables(array $theme): array
    {
        // On a photo the page reads against the photo's average color.
        $bg = $theme['background']['type'] === 'image' && $theme['background']['image_tone'] !== null
            ? $theme['background']['image_tone']
            : $theme['background']['color'];
        $dark = Color::isDark($bg);
        $surface = $dark ? Color::mix($bg, '#FFFFFF', 0.07) : Color::mix($bg, '#FFFFFF', 0.85);
        $text = $dark ? '#F4F4F5' : '#18181B';
        $accent = $theme['accent'];
        $header = $theme['header_color'] ?? $accent;

        $vars = [
            '--color-bg' => $bg,
            '--color-surface' => $surface,
            '--color-border' => Color::mix($surface, $dark ? '#FFFFFF' : '#000000', $dark ? 0.1 : 0.1),
            '--color-text' => $text,
            '--color-muted' => Color::ensureContrast($dark ? '#A1A1AA' : '#52525B', $surface, 4.5),
            '--color-accent' => $accent,
            '--color-on-accent' => Color::readableOn($accent),
            '--color-accent-text' => Color::ensureContrast($accent, $surface, 3.0),
            '--color-heading' => $theme['heading_color'] ?? Color::ensureContrast($accent, $bg, 3.0),
            '--color-header' => $header,
            '--color-on-header' => Color::readableOn($header),
            // Footer links and divider lines sit right on the background: black or white, whichever reads better.
            // With a pattern the background color stays underneath, so it is the fallback for the contrast too.
            '--color-on-bg' => Color::readableOn($bg),
            '--shadow' => $dark
                ? '0 1px 2px rgb(0 0 0 / 0.4), 0 4px 14px rgb(0 0 0 / 0.25)'
                : '0 1px 2px rgb(16 24 40 / 0.06), 0 4px 14px rgb(16 24 40 / 0.06)',
            '--radius' => $theme['radius'] . 'px',
        ];

        foreach ($this->palette as $name => $hex) {
            $vars['--icon-' . $name] = Color::ensureContrast($hex, $surface, 3.0);
        }

        return $vars;
    }

    /**
     * Colors of an icon tile as the page shows it, for the editor and the favicon.
     * Same rules as site.css: tinted = icon color, accent = main color, mono = text color,
     * each on a light wash of that color (8 % for mono, 16 % otherwise) over the card surface.
     *
     * @param array<string, mixed> $theme normalized theme
     * @return array{surface: string, text: string, accent: string, palette: array<string, string>}
     */
    public function iconLook(array $theme): array
    {
        $vars = $this->variables($theme);
        $palette = [];

        foreach (array_keys($this->palette) as $name) {
            $palette[$name] = $vars['--icon-' . $name];
        }

        return [
            'surface' => $vars['--color-surface'],
            'text' => $vars['--color-text'],
            'accent' => $vars['--color-accent-text'],
            'palette' => $palette,
        ];
    }

    /** @param array<string, mixed> $theme normalized theme */
    public function css(array $theme, string $patternUrlBase): string
    {
        $lines = [];

        foreach ($this->variables($theme) as $name => $value) {
            $lines[] = '  ' . $name . ': ' . $value . ';';
        }

        $css = ":root {\n" . implode("\n", $lines) . "\n}\n";

        foreach (array_keys($this->palette) as $name) {
            $css .= ".icon--{$name} { --icon-color: var(--icon-{$name}); }\n";
        }

        $css .= $this->patternCss('.site-header__pattern', 'var(--color-on-header)', $theme['header_pattern'], $patternUrlBase);
        $background = $theme['background'];

        if ($background['type'] === 'image') {
            $css .= ".site__image { background-image: url(/media/{$background['image']}); }\n";
        } else {
            $css .= $this->patternCss('.site__pattern', 'var(--color-on-bg)', $background['pattern'], $patternUrlBase);
        }

        return $css;
    }

    private function patternUrl(string $id, string $base): string
    {
        return $base . '/' . $id . '.svg?v=' . ($this->patterns[$id]['version'] ?? '');
    }

    /**
     * A pattern in the color of what lies on its ground: strength is the opacity, size the
     * zoom of the seamless tile, fade a second mask layer intersected with the pattern.
     *
     * @param array{id: ?string, strength: string, size: string, fade: string} $pattern
     */
    private function patternCss(string $selector, string $color, array $pattern, string $base): string
    {
        if ($pattern['id'] === null) {
            return '';
        }

        $strength = self::PATTERN_STRENGTH[$pattern['strength']] ?? self::PATTERN_STRENGTH['medium'];
        $size = (int) round(($this->patterns[$pattern['id']]['size'] ?? 24) * (self::PATTERN_SIZE[$pattern['size']] ?? 1.0));
        $url = $this->patternUrl($pattern['id'], $base);
        $rules = "background-color: color-mix(in srgb, {$color} {$strength}%, transparent); ";

        $fade = self::PATTERN_FADE[$pattern['fade']] ?? null;
        $rules .= $fade !== null
            ? "mask-image: url({$url}), {$fade}; mask-size: {$size}px, 100% 100%; "
                . 'mask-repeat: repeat, no-repeat; mask-composite: intersect;'
            : "mask-image: url({$url}); mask-size: {$size}px;";

        return "{$selector} { {$rules} }\n";
    }

    /**
     * Contrast problems shown as warnings in the backend (never blocking).
     *
     * @param array<string, mixed> $theme normalized theme
     * @return list<array{field: string, ratio: float}>
     */
    public function warnings(array $theme): array
    {
        $warnings = [];
        $header = $theme['header_color'] ?? $theme['accent'];
        $ratio = Color::contrast($header, Color::readableOn($header));

        if ($ratio < 4.5) {
            $warnings[] = ['field' => 'header_color', 'ratio' => round($ratio, 1)];
        }

        if ($theme['heading_color'] !== null) {
            $ratio = Color::contrast($theme['heading_color'], $theme['background']['color']);

            if ($ratio < 3.0) {
                $warnings[] = ['field' => 'heading_color', 'ratio' => round($ratio, 1)];
            }
        }

        return $warnings;
    }
}
