<?php

declare(strict_types=1);

namespace Bioly\Icon;

/**
 * Icons of the backend interface: resources/ui-icons/<name>.svg (Lucide).
 * Separate from the icon library so the picker never shows them.
 */
final class UiIcons
{
    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(private readonly string $path) {}

    /**
     * Inline SVG, decorative, sized and colored by CSS. Empty string if the icon is missing.
     */
    public function html(string $name, string $class = 'ui-icon'): string
    {
        if (!isset($this->cache[$name])) {
            $file = $this->path . '/' . $name . '.svg';
            $svg = preg_match('/^[a-z0-9-]+$/', $name) === 1 && is_file($file)
                ? IconRegistry::clean((string) file_get_contents($file))
                : null;
            $this->cache[$name] = $svg ?? '';
        }

        if ($this->cache[$name] === '') {
            return '';
        }

        return (string) preg_replace(
            '/^<svg\b/',
            '<svg class="' . htmlspecialchars($class, ENT_QUOTES) . '" aria-hidden="true" focusable="false"',
            $this->cache[$name],
            1,
        );
    }
}
