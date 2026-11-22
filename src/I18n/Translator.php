<?php

declare(strict_types=1);

namespace Bioly\I18n;

final class Translator
{
    /** @var array<string, array<string, string>> */
    private array $catalogues = [];

    /**
     * @param list<string> $paths folders holding de.json, en.json, ... (later folders win)
     */
    public function __construct(
        private array $paths,
        private string $locale,
        private readonly string $fallbackLocale,
    ) {}

    public function locale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function addPath(string $path): void
    {
        $this->paths[] = $path;
        $this->catalogues = [];
    }

    /**
     * Returns the translation for a key like "backend.links.add".
     * Placeholders like {name} are replaced with the given parameters.
     *
     * @param array<string, string|int|float> $parameters
     */
    public function translate(string $key, array $parameters = []): string
    {
        $text = $this->catalogue($this->locale)[$key]
            ?? $this->catalogue($this->fallbackLocale)[$key]
            ?? $key;

        foreach ($parameters as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }

        return $text;
    }

    /**
     * All texts of the current locale (fallback filled in), optionally only keys with a prefix.
     *
     * @param list<string> $prefixes
     * @return array<string, string>
     */
    public function all(array $prefixes = []): array
    {
        $texts = array_replace($this->catalogue($this->fallbackLocale), $this->catalogue($this->locale));

        if ($prefixes === []) {
            return $texts;
        }

        return array_filter($texts, static function (string $key) use ($prefixes): bool {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    return true;
                }
            }

            return false;
        }, ARRAY_FILTER_USE_KEY);
    }

    public function has(string $key): bool
    {
        return isset($this->catalogue($this->locale)[$key]) || isset($this->catalogue($this->fallbackLocale)[$key]);
    }

    /** @return array<string, string> */
    private function catalogue(string $locale): array
    {
        if (isset($this->catalogues[$locale])) {
            return $this->catalogues[$locale];
        }

        $merged = [];

        foreach ($this->paths as $path) {
            $file = $path . '/' . $locale . '.json';

            if (!is_file($file)) {
                continue;
            }

            $data = json_decode((string) file_get_contents($file), true);

            if (is_array($data)) {
                $merged = array_merge($merged, array_map('strval', $data));
            }
        }

        return $this->catalogues[$locale] = $merged;
    }
}
