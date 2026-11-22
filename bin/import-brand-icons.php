<?php

declare(strict_types=1);

// Imports platform logos from an unpacked Simple Icons npm package and the SVG Logos collection.
// Usage: php bin/import-brand-icons.php /path/to/simple-icons/package /path/to/svg-logos
//
// Only icons from the list below that have no license of their own or exactly CC0-1.0 are taken.
// Each logo is written twice:
//   resources/originals/<slug>.svg      as close to the original as possible ("Markenfarbe"):
//                                       multi-colored from SVG Logos (CC0-1.0) where that is closer,
//                                       otherwise Simple Icons in the brand color or with a gradient
//   resources/icons/<farbe>/<slug>.svg  Simple Icons in currentColor, in the color folder closest to the
//                                       brand color, so it follows the icon style like the library icons
// resources/originals/brands.json lists them with title, brand color and color folder.

use Bioly\Support\SvgSanitizer;
use Bioly\Theme\Color;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

const SLUGS = [
    // essential: social, messengers, streaming, creators, portfolios
    'instagram', 'tiktok', 'youtube', 'facebook', 'x', 'threads', 'snapchat', 'whatsapp', 'telegram', 'signal',
    'messenger', 'discord', 'twitch', 'kick', 'spotify', 'applemusic', 'soundcloud', 'youtubemusic', 'pinterest',
    'reddit', 'bluesky', 'mastodon', 'tumblr', 'github', 'paypal', 'patreon', 'kofi', 'buymeacoffee', 'vimeo',
    'behance', 'dribbble', 'substack', 'medium', 'etsy',
    // useful extras: music and podcasts, funding, games, hobbies, places, selling, business, photos
    'deezer', 'tidal', 'bandcamp', 'applepodcasts', 'mixcloud', 'gofundme', 'kickstarter', 'onlyfans', 'cashapp',
    'gitlab', 'stackoverflow', 'steam', 'playstation', 'goodreads', 'strava', 'komoot', 'googlemaps', 'ebay',
    'vinted', 'xing', 'calendly', 'artstation', 'flickr',
];

// Originals taken from SVG Logos (https://github.com/gilbarbara/logos, CC0-1.0), because they are
// multi-colored like the real logo. Only logos that are current there; outdated ones stay Simple Icons.
// "dark" = this color becomes currentColor, so the logo stays visible on dark pages (TikTok's note).
const SVG_LOGOS = [
    'dribbble' => ['file' => 'dribbble-icon.svg'],
    'facebook' => ['file' => 'facebook.svg'],
    'flickr' => ['file' => 'flickr-icon.svg'],
    'gitlab' => ['file' => 'gitlab-icon.svg'],
    'googlemaps' => ['file' => 'google-maps.svg'],
    'messenger' => ['file' => 'messenger.svg'],
    'paypal' => ['file' => 'paypal.svg'],
    'reddit' => ['file' => 'reddit-icon.svg'],
    'stackoverflow' => ['file' => 'stackoverflow-icon.svg'],
    'telegram' => ['file' => 'telegram.svg'],
    'tiktok' => ['file' => 'tiktok-icon.svg', 'dark' => '#000000'],
    'whatsapp' => ['file' => 'whatsapp-icon.svg'],
    'youtube' => ['file' => 'youtube-icon.svg'],
];

// Originals from Simple Icons with a gradient instead of one color, where the real logo has one
// and no free multi-colored version exists. The shape stays the Simple Icons path (CC0-1.0).
const GRADIENTS = [
    // Instagram's well-known gradient, from yellow at the bottom left over orange and pink to purple.
    'instagram' => '<radialGradient id="bioly-instagram-a" cx="6.4" cy="25.8" r="30" gradientUnits="userSpaceOnUse">'
        . '<stop offset="0" stop-color="#FFD600"/><stop offset=".25" stop-color="#FF7A00"/>'
        . '<stop offset=".5" stop-color="#FF0069"/><stop offset=".85" stop-color="#D300C5"/>'
        . '<stop offset="1" stop-color="#7638FA"/></radialGradient>',
    // The logo itself is black or white; Steam's blue is the one of its app and store.
    'steam' => '<linearGradient id="bioly-steam-a" x1="0" y1="0" x2="0" y2="24" gradientUnits="userSpaceOnUse">'
        . '<stop offset="0" stop-color="#1B2838"/><stop offset="1" stop-color="#1A9FFF"/></linearGradient>',
];

// Originals from Simple Icons in the brand color with a dark outline, where the real logo has one.
// Without it, Snapchat's yellow ghost would be almost invisible on light pages.
const OUTLINED = [
    'snapchat' => ['fill' => '#FFFC00', 'stroke' => '#000000'],
];

$package = rtrim($argv[1] ?? '', '/');
$svgLogos = rtrim($argv[2] ?? '', '/');
$manifest = json_decode((string) @file_get_contents($package . '/package.json'), true);
$data = json_decode((string) @file_get_contents($package . '/data/simple-icons.json'), true);

if (!is_array($manifest) || ($manifest['name'] ?? '') !== 'simple-icons' || !is_array($data)) {
    fwrite(STDERR, "Usage: php bin/import-brand-icons.php /path/to/simple-icons/package /path/to/svg-logos\n");
    exit(1);
}

$svgLogosLicense = (string) @file_get_contents($svgLogos . '/LICENSE.txt');

if (!str_starts_with($svgLogosLicense, 'CC0 1.0 Universal') || !is_dir($svgLogos . '/logos')) {
    fwrite(STDERR, "The second path must be the unpacked SVG Logos repository (with LICENSE.txt and logos/).\n");
    exit(1);
}

// One multi-colored SVG Logos file, cleaned, with fixed ids, ready for resources/originals/.
$fromSvgLogos = static function (string $slug, array $entry) use ($svgLogos): ?string {
    $clean = SvgSanitizer::sanitize((string) @file_get_contents($svgLogos . '/logos/' . $entry['file']));

    if ($clean === null) {
        return null;
    }

    // Ids get a fixed prefix per logo instead of a random one, so a new import only changes what changed.
    $clean = (string) preg_replace('/\bs[0-9a-f]{8}-/', 'bioly-' . $slug . '-', $clean);
    $clean = (string) preg_replace_callback('/^<svg\b[^>]*>/', static fn(array $m): string => (string) preg_replace('/\s(width|height|version)="[^"]*"/', '', $m[0]), $clean);
    $clean = (string) preg_replace('/<title>.*?<\/title>/s', '', $clean);

    if (isset($entry['dark'])) {
        $clean = str_ireplace('fill="' . $entry['dark'] . '"', 'fill="currentColor"', $clean);
    }

    return $clean;
};

$version = (string) $manifest['version'];
$palette = json_decode((string) file_get_contents($root . '/resources/icons/colors.json'), true);
$originals = $root . '/resources/originals';
$icons = $root . '/resources/icons';

// Slug rule of Simple Icons for entries without an explicit slug.
$slugOf = static function (array $icon): string {
    if (isset($icon['slug'])) {
        return (string) $icon['slug'];
    }

    $title = strtolower((string) $icon['title']);
    $title = strtr($title, ['+' => 'plus', '.' => 'dot', '&' => 'and', 'đ' => 'd', 'ħ' => 'h', 'ı' => 'i', 'ĸ' => 'k', 'ŀ' => 'l', 'ł' => 'l', 'ß' => 'ss', 'ŧ' => 't']);
    $title = (string) iconv('UTF-8', 'ASCII//TRANSLIT', $title);

    return (string) preg_replace('/[^a-z0-9]/', '', $title);
};

// Color folder closest to the brand color; greys, near black and near white go to "grau".
$folderFor = static function (string $hex): string {
    [$r, $g, $b] = array_map(static fn(int $v): float => $v / 255, Color::rgb('#' . $hex));
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $light = ($max + $min) / 2;
    $delta = $max - $min;
    $saturation = $delta == 0.0 ? 0.0 : $delta / (1 - abs(2 * $light - 1));

    if ($saturation < 0.25 || $light < 0.12 || $light > 0.93) {
        return 'grau';
    }

    $hue = match (true) {
        $max === $r => fmod((($g - $b) / $delta) + 6, 6),
        $max === $g => (($b - $r) / $delta) + 2,
        default => (($r - $g) / $delta) + 4,
    } * 60;

    return match (true) {
        $hue < 15 || $hue >= 345 => 'rot',
        $hue < 40 => 'orange',
        $hue < 70 => 'gelb',
        $hue < 170 => 'gruen',
        $hue < 255 => 'blau',
        $hue < 290 => 'lila',
        default => 'pink',
    };
};

// Lucide names already in the color folders must not be shadowed.
$lucide = [];

foreach (glob($icons . '/*/*.svg') ?: [] as $file) {
    if (!str_contains((string) file_get_contents($file), 'Simple Icons')) {
        $lucide[basename($file, '.svg')] = true;
    }
}

// Remove the logos of an earlier import first, so dropped ones disappear.
$previous = json_decode((string) @file_get_contents($originals . '/brands.json'), true);

foreach (is_array($previous) ? $previous['icons'] ?? [] : [] as $slug => $info) {
    @unlink($originals . '/' . $slug . '.svg');
    @unlink($icons . '/' . $info['folder'] . '/' . $slug . '.svg');
}

$bySlug = [];

foreach ($data as $icon) {
    if (is_array($icon)) {
        $bySlug[$slugOf($icon)] = $icon;
    }
}

$written = [];
$skipped = [];

foreach (SLUGS as $slug) {
    $icon = $bySlug[$slug] ?? null;
    $license = $icon['license']['type'] ?? null;

    if ($icon === null) {
        $skipped[] = "{$slug}: not in Simple Icons {$version}";
        continue;
    }

    if ($license !== null && $license !== 'CC0-1.0') {
        $skipped[] = "{$slug}: own license {$license}";
        continue;
    }

    if (isset($lucide[$slug])) {
        $skipped[] = "{$slug}: name already used by a Lucide icon";
        continue;
    }

    $svg = (string) @file_get_contents($package . '/icons/' . $slug . '.svg');

    if (preg_match_all('/<path d="([^"]+)"\s*\/>/', $svg, $paths) !== 1 || preg_match('/<(?!svg|\/svg|title|\/title|path)[a-z]/i', $svg) === 1) {
        $skipped[] = "{$slug}: unexpected SVG structure";
        continue;
    }

    $hex = strtoupper((string) $icon['hex']);
    $folder = $folderFor($hex);
    $title = (string) $icon['title'];
    $luminance = Color::luminance('#' . $hex);
    // Black or white brand colors would vanish on dark or light pages: they use the text color.
    $fill = $luminance < 0.07 || $luminance > 0.85 ? 'currentColor' : '#' . $hex;
    $comment = '<!-- ' . $title . ', Simple Icons ' . $version . ', CC0-1.0, https://simpleicons.org. The logo is a trademark of its owner. -->';
    $path = '<path d="' . $paths[1][0] . '"/>';

    $original = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="' . $fill . '">' . $path . '</svg>';
    $originalComment = $comment;
    $source = 'simple-icons';

    if (isset(SVG_LOGOS[$slug])) {
        $multi = $fromSvgLogos($slug, SVG_LOGOS[$slug]);

        if ($multi === null) {
            $skipped[] = "{$slug}: SVG Logos file " . SVG_LOGOS[$slug]['file'] . ' missing or unusable, Simple Icons used instead';
        } else {
            $original = $multi;
            $originalComment = '<!-- ' . $title . ', SVG Logos by Gil Barbara (' . SVG_LOGOS[$slug]['file'] . '), CC0-1.0, https://github.com/gilbarbara/logos. The logo is a trademark of its owner. -->';
            $source = 'svg-logos';
        }
    } elseif (isset(GRADIENTS[$slug])) {
        $id = (string) preg_replace('/.*\sid="([^"]+)".*/s', '$1', GRADIENTS[$slug]);
        $original = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="url(#' . $id . ')"><defs>' . GRADIENTS[$slug] . '</defs>' . $path . '</svg>';
        $originalComment = '<!-- ' . $title . ', Simple Icons ' . $version . ', CC0-1.0, https://simpleicons.org, with a gradient in the brand colors. The logo is a trademark of its owner. -->';
        $source = 'simple-icons+gradient';
    } elseif (isset(OUTLINED[$slug])) {
        // The outline is the same path drawn wider below the filled one, so only its outer half shows.
        $outline = '<path d="' . $paths[1][0] . '" fill="none" stroke="' . OUTLINED[$slug]['stroke'] . '" stroke-width="2" stroke-linejoin="round"/>';
        $original = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="-1 -1 26 26" fill="' . OUTLINED[$slug]['fill'] . '">' . $outline . $path . '</svg>';
        $originalComment = '<!-- ' . $title . ', Simple Icons ' . $version . ', CC0-1.0, https://simpleicons.org, with an outline like the real logo. The logo is a trademark of its owner. -->';
        $source = 'simple-icons+outline';
    }

    file_put_contents($originals . '/' . $slug . '.svg', $originalComment . "\n" . $original . "\n");
    file_put_contents($icons . '/' . $folder . '/' . $slug . '.svg', $comment . "\n" . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">' . $path . "</svg>\n");

    $written[$slug] = ['title' => $title, 'hex' => $hex, 'folder' => $folder, 'brand_color' => $source !== 'simple-icons' || $fill !== 'currentColor', 'original' => $source];
}

ksort($written);
file_put_contents($originals . '/brands.json', json_encode([
    'source' => 'Simple Icons ' . $version . ' (https://simpleicons.org), CC0-1.0; originals partly from SVG Logos (https://github.com/gilbarbara/logos), CC0-1.0',
    'icons' => $written,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

echo count($written) . " logos imported from Simple Icons {$version}, "
    . count(array_filter($written, static fn(array $icon): bool => $icon['original'] === 'svg-logos')) . " originals from SVG Logos.\n";

foreach ($skipped as $line) {
    echo "  skipped {$line}\n";
}
