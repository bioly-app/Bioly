<?php

declare(strict_types=1);

namespace Bioly\Service;

use Bioly\Icon\IconRegistry;
use Bioly\Theme\Color;
use Bioly\Theme\Theme;

/**
 * Builds the favicon as SVG from the design settings:
 * auto (first letter on the main color), an icon from the library drawn like an icon tile
 * of the page (follows the icon style), or an own SVG from the media library as it is.
 */
final class FaviconService
{
    public function __construct(
        private readonly IconRegistry $icons,
        private readonly MediaService $media,
        private readonly Theme $theme,
    ) {}

    /**
     * @param array<string, mixed> $theme normalized theme (accent, favicon)
     */
    public function svg(array $theme, string $siteName): string
    {
        $favicon = $theme['favicon'];

        if ($favicon['type'] === 'upload' && is_string($favicon['file'])) {
            $own = $this->ownSvg($favicon['file'], $theme);

            if ($own !== null) {
                return $own;
            }
        }

        if ($favicon['type'] === 'icon' && is_string($favicon['icon'])) {
            $icon = $this->icons->get($favicon['icon']);

            if ($icon !== null) {
                return $this->fromIcon($icon['svg'], $icon['color'], $theme);
            }
        }

        return $this->auto($theme['accent'], $siteName);
    }

    /**
     * Icon tile like on the page. $colorName null: an own icon with its own colors, drawn as is.
     *
     * @param array<string, mixed> $theme
     */
    private function fromIcon(string $svg, ?string $colorName, array $theme): string
    {
        $look = $this->theme->iconLook($theme);
        [$color, $wash] = match (true) {
            $colorName === null => [$look['text'], '0.06'],
            $theme['icon_style'] === 'accent' => [$look['accent'], '0.16'],
            $theme['icon_style'] === 'mono' => [$look['text'], '0.08'],
            default => [$look['palette'][$colorName] ?? $look['accent'], '0.16'],
        };

        // Icons draw with currentColor, which a favicon cannot inherit. The icon keeps its
        // own viewBox and is placed as a nested svg, so any icon size fits the tile.
        $icon = str_replace('currentColor', $color, $svg);
        $icon = (string) preg_replace('/^<svg\b/', '<svg x="6" y="6" width="20" height="20"', $icon, 1);

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
            . '<rect width="32" height="32" rx="8" fill="' . $look['surface'] . '"/>'
            . '<rect width="32" height="32" rx="8" fill="' . $color . '" fill-opacity="' . $wash . '"/>'
            . $icon . '</svg>';
    }

    /**
     * Own SVG from the media library, without a tile. A one-color icon gets its palette color
     * in the look of the page. Older favicon-*.svg files (uploaded before 0.1.6) still work.
     *
     * @param array<string, mixed> $theme
     */
    private function ownSvg(string $file, array $theme): ?string
    {
        if (!str_starts_with($file, 'icon-')) {
            return $this->media->contents($file);
        }

        $icon = $this->icons->get('own:' . substr(basename($file, '.svg'), 5));

        if ($icon === null) {
            return null;
        }

        if ($icon['color'] === null) {
            return $icon['svg'];
        }

        $look = $this->theme->iconLook($theme);
        $color = match ($theme['icon_style']) {
            'accent' => $look['accent'],
            'mono' => $look['text'],
            default => $look['palette'][$icon['color']] ?? $look['accent'],
        };

        return str_replace('currentColor', $color, $icon['svg']);
    }

    private function auto(string $accent, string $siteName): string
    {
        $letter = mb_strtoupper(mb_substr(trim($siteName), 0, 1)) ?: 'B';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
            . '<rect width="32" height="32" rx="8" fill="' . $accent . '"/>'
            . '<text x="16" y="22" text-anchor="middle" font-family="system-ui, -apple-system, Segoe UI, sans-serif" font-size="18" font-weight="700" fill="'
            . Color::readableOn($accent) . '">' . htmlspecialchars($letter, ENT_XML1) . '</text></svg>';
    }
}
