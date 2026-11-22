<?php

declare(strict_types=1);

namespace Bioly\Update;

use Bioly\Repository\SettingsRepository;

/**
 * Remembers what the last check found (settings "update"), so the backend can show
 * "Update available" without asking GitHub on every page. Asks at most once a day,
 * and never when the owner switched the check off.
 */
final class UpdateCheck
{
    public const EVERY_SECONDS = 24 * 3600;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ReleaseFeed $feed,
        private readonly string $currentVersion,
    ) {}

    public function enabled(): bool
    {
        return ($this->settings->getArray('update')['auto'] ?? true) !== false;
    }

    public function setEnabled(bool $on): void
    {
        $this->settings->merge('update', ['auto' => $on]);
    }

    public function due(): bool
    {
        $checked = (int) ($this->settings->getArray('update')['checked_at'] ?? 0);

        return $this->enabled() && time() - $checked >= self::EVERY_SECONDS;
    }

    /** Asks GitHub now and stores the answer. */
    public function run(): void
    {
        try {
            $latest = $this->feed->latest();
            $this->settings->merge('update', ['checked_at' => time(), 'latest' => $latest, 'error' => null]);
        } catch (UpdateException $exception) {
            $this->settings->merge('update', ['checked_at' => time(), 'error' => $exception->getMessage()]);
        }
    }

    /**
     * What the last check found.
     *
     * @return array{checked_at: int, error: ?string, latest: ?array{version: string, notes: string, page: string, zip: string, size: int}, available: bool}
     */
    public function state(): array
    {
        $stored = $this->settings->getArray('update');
        $raw = $stored['latest'] ?? null;
        $latest = is_array($raw) && is_string($raw['version'] ?? null) ? [
            'version' => $raw['version'],
            'notes' => (string) ($raw['notes'] ?? ''),
            'page' => (string) ($raw['page'] ?? ''),
            'zip' => (string) ($raw['zip'] ?? ''),
            'size' => (int) ($raw['size'] ?? 0),
        ] : null;

        return [
            'checked_at' => (int) ($stored['checked_at'] ?? 0),
            'error' => is_string($stored['error'] ?? null) ? $stored['error'] : null,
            'latest' => $latest,
            'available' => $latest !== null && version_compare($latest['version'], $this->currentVersion, '>'),
        ];
    }
}
