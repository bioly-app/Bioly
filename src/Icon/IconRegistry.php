<?php

declare(strict_types=1);

namespace Bioly\Icon;

use Bioly\I18n\Translator;
use Bioly\Support\SvgSanitizer;

/**
 * Reads the icon folders. Standard icons: resources/icons/<color>/<name>.svg,
 * originals: resources/originals/<name>.svg. Ids look like "std:heart" or "orig:instagram".
 * The scan result is cached and rebuilt when a folder changes.
 * Own icons from the media library come on top ("own:<16 hex>"); they are not cached.
 */
final class IconRegistry
{
    /** @var array{icons: array<string, array{id: string, name: string, source: string, color: ?string, svg: string, brand?: bool, label?: string}>, problems: list<string>}|null */
    private ?array $data = null;

    /** @var array<string, string>|null */
    private ?array $palette = null;

    /** @var array<string, array{id: string, name: string, source: string, color: ?string, svg: string, label?: string}>|null */
    private ?array $own = null;

    /**
     * @param (\Closure(): list<array{filename: string, label: ?string, color: ?string, svg: string}>)|null $ownIcons
     */
    public function __construct(
        private readonly string $iconsPath,
        private readonly string $originalsPath,
        private readonly string $cacheFile,
        private readonly ?\Closure $ownIcons = null,
    ) {}

    /** Call after an own icon was added, changed or deleted. */
    public function forgetOwn(): void
    {
        $this->own = null;
    }

    /** @return array<string, string> color name => hex */
    public function palette(): array
    {
        if ($this->palette === null) {
            $data = json_decode((string) @file_get_contents($this->iconsPath . '/colors.json'), true);
            $this->palette = is_array($data) ? array_filter($data, static fn($hex): bool => is_string($hex)) : [];
        }

        return $this->palette;
    }

    /** @return array{id: string, name: string, source: string, color: ?string, svg: string}|null */
    public function get(string $id): ?array
    {
        return str_starts_with($id, 'own:') ? ($this->own()[$id] ?? null) : ($this->load()['icons'][$id] ?? null);
    }

    public function has(string $id): bool
    {
        return $this->get($id) !== null;
    }

    /**
     * Inline SVG ready for the page: decorative, colored by CSS.
     */
    public function html(string $id, string $class = 'icon'): string
    {
        $icon = $this->get($id);

        if ($icon === null) {
            return '';
        }

        $classes = $class . ($icon['color'] !== null ? ' icon--' . $icon['color'] : ' icon--original');

        return (string) preg_replace(
            '/^<svg\b/',
            '<svg class="' . htmlspecialchars($classes, ENT_QUOTES) . '" aria-hidden="true" focusable="false"',
            $icon['svg'],
            1,
        );
    }

    /**
     * For the icon picker.
     *
     * @return list<array{id: string, label: string, source: string, color: ?string, brand: bool, svg: string}>
     */
    public function listing(Translator $translator): array
    {
        $list = [];

        foreach ([...$this->load()['icons'], ...$this->own()] as $icon) {
            $key = 'icon.' . $icon['name'];
            $list[] = [
                'id' => $icon['id'],
                'label' => $icon['label'] ?? ($translator->has($key) ? $translator->translate($key) : self::humanize($icon['name'])),
                'source' => $icon['source'],
                'color' => $icon['color'],
                'brand' => !empty($icon['brand']),
                'svg' => $this->html($icon['id']),
            ];
        }

        usort($list, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $list;
    }

    /** @return array<string, array{id: string, name: string, source: string, color: ?string, svg: string, label?: string}> */
    private function own(): array
    {
        if ($this->own !== null) {
            return $this->own;
        }

        $own = [];

        foreach ($this->ownIcons !== null ? ($this->ownIcons)() : [] as $row) {
            $hex = substr(basename($row['filename'], '.svg'), 5);
            $svg = self::clean($row['svg']);

            if ($svg === null || preg_match('/^[a-f0-9]{16}$/', $hex) !== 1) {
                continue;
            }

            $color = $row['color'] !== null && isset($this->palette()[$row['color']]) ? $row['color'] : null;
            $own['own:' . $hex] = [
                'id' => 'own:' . $hex,
                'name' => 'own-' . $hex,
                'source' => 'own',
                'color' => $color,
                'svg' => $color !== null ? (self::clean(SvgSanitizer::monochrome($svg)) ?? $svg) : $svg,
                'label' => (string) ($row['label'] ?? 'Icon'),
            ];
        }

        return $this->own = $own;
    }

    /** @return list<string> files that were skipped, with the reason */
    public function problems(): array
    {
        return $this->load()['problems'];
    }

    public static function humanize(string $name): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $name));
    }

    /**
     * Returns the cleaned SVG or null if the file is not a safe, usable icon.
     */
    public static function clean(string $svg): ?string
    {
        $svg = trim((string) preg_replace('/<\?xml.*?\?>|<!--.*?-->|<!DOCTYPE.*?>/s', '', $svg));

        $forbidden = '/<(script|foreignObject|image|iframe|style|use)\b|\son[a-z]+\s*=|javascript:|xlink:href|href\s*=/i';

        if (!str_starts_with($svg, '<svg') || !str_contains($svg, 'viewBox') || preg_match($forbidden, $svg) === 1) {
            return null;
        }

        // Size comes from CSS; drop fixed sizes and classes of the source.
        $svg = (string) preg_replace('/^<svg\b([^>]*)>/', '<svg$1>', $svg);
        $svg = (string) preg_replace_callback('/^<svg\b[^>]*>/', static fn(array $m): string => (string) preg_replace('/\s(width|height|class)="[^"]*"/', '', $m[0]), $svg);

        // One line: Lucide files put every attribute on its own line.
        $svg = (string) preg_replace('/\s+/', ' ', $svg);

        return (string) preg_replace('/\s*(\/?>)\s*/', '$1', $svg);
    }

    /** @return array{icons: array<string, array{id: string, name: string, source: string, color: ?string, svg: string, brand?: bool, label?: string}>, problems: list<string>} */
    private function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $signature = $this->signature();

        if (is_file($this->cacheFile)) {
            $cached = @include $this->cacheFile;

            if (is_array($cached) && ($cached['signature'] ?? null) === $signature) {
                return $this->data = $cached['data'];
            }
        }

        $this->data = $this->scan();
        @file_put_contents(
            $this->cacheFile,
            '<?php return ' . var_export(['signature' => $signature, 'data' => $this->data], true) . ';',
            LOCK_EX,
        );

        return $this->data;
    }

    /** @return array{icons: array<string, array{id: string, name: string, source: string, color: ?string, svg: string, brand?: bool, label?: string}>, problems: list<string>} */
    private function scan(): array
    {
        $icons = [];
        $problems = [];
        $palette = $this->palette();
        // Platform logos come in two versions: close to the real logo in originals/,
        // currentColor in a color folder. brands.json names them.
        $brands = json_decode((string) @file_get_contents($this->originalsPath . '/brands.json'), true);
        $brands = is_array($brands['icons'] ?? null) ? $brands['icons'] : [];

        foreach (glob($this->iconsPath . '/*', GLOB_ONLYDIR) ?: [] as $folder) {
            $color = basename($folder);

            if (!isset($palette[$color])) {
                $problems[] = 'icons/' . $color . ': unknown color, not in colors.json';
                continue;
            }

            foreach (glob($folder . '/*.svg') ?: [] as $file) {
                $this->add($icons, $problems, $file, 'std', $color, $brands);
            }
        }

        foreach (glob($this->originalsPath . '/*.svg') ?: [] as $file) {
            $this->add($icons, $problems, $file, 'orig', null, $brands);
        }

        return ['icons' => $icons, 'problems' => $problems];
    }

    /**
     * @param array<string, array{id: string, name: string, source: string, color: ?string, svg: string, brand?: bool, label?: string}> $icons
     * @param list<string> $problems
     * @param array<string, array{title?: string}> $brands
     */
    private function add(array &$icons, array &$problems, string $file, string $source, ?string $color, array $brands = []): void
    {
        $name = basename($file, '.svg');
        $id = $source . ':' . $name;
        $svg = preg_match('/^[a-z0-9-]+$/', $name) === 1 ? self::clean((string) file_get_contents($file)) : null;

        if ($svg === null) {
            $problems[] = $file . ': invalid name or unsafe/invalid SVG';
            return;
        }

        if (isset($icons[$id])) {
            $problems[] = $file . ': name already used in another color folder';
            return;
        }

        $icons[$id] = ['id' => $id, 'name' => $name, 'source' => $source, 'color' => $color, 'svg' => $svg];

        if (isset($brands[$name])) {
            $icons[$id]['brand'] = true;
            $icons[$id]['label'] = (string) ($brands[$name]['title'] ?? self::humanize($name));
        }
    }

    private function signature(): string
    {
        $parts = [];

        foreach ([$this->iconsPath, ...(glob($this->iconsPath . '/*', GLOB_ONLYDIR) ?: []), $this->originalsPath, $this->originalsPath . '/brands.json'] as $dir) {
            $parts[] = $dir . ':' . @filemtime($dir);
        }

        $parts[] = (string) @filemtime($this->iconsPath . '/colors.json');

        return md5(implode('|', $parts));
    }
}
