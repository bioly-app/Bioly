<?php

declare(strict_types=1);

namespace Bioly\Repository;

use Bioly\Support\Clock;

final class SettingsRepository extends Repository
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->load()[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function getArray(string $key): array
    {
        $value = $this->get($key, []);

        return is_array($value) ? $value : [];
    }

    public function set(string $key, mixed $value): void
    {
        $this->run(
            'INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)
             ON CONFLICT (key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            [$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), Clock::now()],
        );
        $this->cache = null;
    }

    /** Merges the given values into an array setting. */
    /** @param array<string, mixed> $values */
    public function merge(string $key, array $values): void
    {
        $this->set($key, array_replace($this->getArray($key), $values));
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        if ($this->cache === null) {
            $this->cache = [];

            foreach ($this->all('SELECT key, value FROM settings') as $row) {
                $this->cache[$row['key']] = json_decode($row['value'], true);
            }
        }

        return $this->cache;
    }
}
