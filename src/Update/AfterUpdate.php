<?php

declare(strict_types=1);

namespace Bioly\Update;

use Bioly\Database\Migrator;
use Bioly\Page\Publisher;
use Bioly\Repository\SettingsRepository;
use Bioly\Service\MediaService;

/**
 * Runs once after new program files were put in place (a new VERSION):
 * migrations, catching up stored data, then the public page with the new version.
 * Works without a command line, so it also runs on shared hosting with a ZIP update.
 */
final class AfterUpdate
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly MediaService $media,
        private readonly Publisher $publisher,
        private readonly SettingsRepository $settings,
        private readonly string $version,
        private readonly string $markerFile,
    ) {}

    /** Cheap check on every request: one small file. */
    public function needed(): bool
    {
        return @file_get_contents($this->markerFile) !== $this->version;
    }

    public function run(): void
    {
        $lock = fopen($this->markerFile . '.lock', 'c');

        // Two requests at once: the second one waits and then finds the work done.
        if ($lock === false || !flock($lock, LOCK_EX)) {
            return;
        }

        try {
            if (!$this->needed()) {
                return;
            }

            $this->migrator->migrate();
            $this->media->backfill();
            // A newer version may clean SVGs more strictly: run the stored ones through it again.
            $this->media->resanitizeSvgs();

            // Rebuild the public page with the new CSS, JavaScript and data, if it was ever published.
            if (isset($this->settings->getArray('publish')['at'])) {
                $this->publisher->publish();
            }

            file_put_contents($this->markerFile, $this->version, LOCK_EX);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
