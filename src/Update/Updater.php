<?php

declare(strict_types=1);

namespace Bioly\Update;

use Throwable;

/**
 * Puts a checked Package in place:
 * 1. checks: not a development copy, newer version, PHP new enough, folder writable
 * 2. backup of program files and database (Backups)
 * 3. copies the new files over the old ones; files the old version had and the new one
 *    does not are deleted (only those listed in the old manifest.json)
 * 4. notes the update in data/updates/pending.json
 * The next request runs migrations and the new page (AfterUpdate). If that fails,
 * RunAfterUpdate restores the backup (see finishFailed()).
 * If copying fails, the backup is put back right away.
 */
final class Updater
{
    public function __construct(
        private readonly string $root,
        private readonly string $updatesFolder,
        private readonly string $currentVersion,
        private readonly Backups $backups,
        private readonly bool $development,
    ) {}

    /**
     * Reasons why updates cannot run here at all, as translation keys (empty: fine).
     *
     * @return list<string>
     */
    public function blockers(): array
    {
        $reasons = [];

        if ($this->development || is_dir($this->root . '/.git')) {
            $reasons[] = 'update.blocked.development';
        }

        if (!class_exists(\ZipArchive::class)) {
            $reasons[] = 'update.error.no_zip';
        }

        if (!is_writable($this->root) || !is_writable($this->root . '/src')) {
            $reasons[] = 'update.blocked.writable';
        }

        return $reasons;
    }

    public function install(Package $package): void
    {
        if ($this->blockers() !== []) {
            throw new UpdateException($this->blockers()[0]);
        }

        if (version_compare($package->version, $this->currentVersion, '<=')) {
            throw new UpdateException('update.error.not_newer');
        }

        if (version_compare(PHP_VERSION, $package->php, '<')) {
            throw new UpdateException('update.error.php');
        }

        // Load what a failure needs now, before its files are replaced.
        class_exists(Files::class);
        class_exists(UpdateException::class);

        $backup = $this->backups->create();
        $oldFiles = $this->manifestFiles($this->root . '/manifest.json');

        try {
            foreach (array_keys($package->files) as $file) {
                if (str_starts_with($file, 'data/')) {
                    // data/ belongs to the owner; only a missing .htaccess is added.
                    if ($file === 'data/.htaccess' && !is_file($this->root . '/' . $file)) {
                        Files::copy($package->root . '/' . $file, $this->root . '/' . $file);
                    }

                    continue;
                }

                Files::copy($package->root . '/' . $file, $this->root . '/' . $file);
            }

            foreach (['manifest.json', 'manifest.sig'] as $meta) {
                if (is_file($package->root . '/' . $meta)) {
                    Files::copy($package->root . '/' . $meta, $this->root . '/' . $meta);
                }
            }

            foreach (array_diff($oldFiles, array_keys($package->files)) as $gone) {
                if (!str_starts_with($gone, 'data/')) {
                    @unlink($this->root . '/' . $gone);
                    Files::removeEmptyFolders($this->root, $gone);
                }
            }
        } catch (Throwable $exception) {
            $this->backups->restore($backup);

            throw new UpdateException('update.error.copy', 0, $exception);
        }

        file_put_contents($this->pendingFile(), json_encode(['backup' => $backup, 'from' => $this->currentVersion, 'to' => $package->version]) . "\n");
    }

    /** @return array{backup: string, from: string, to: string}|null */
    public function pending(): ?array
    {
        $data = json_decode((string) @file_get_contents($this->pendingFile()), true);

        return is_array($data) && is_string($data['backup'] ?? null) ? ['backup' => $data['backup'], 'from' => (string) ($data['from'] ?? ''), 'to' => (string) ($data['to'] ?? '')] : null;
    }

    /** AfterUpdate worked: the update is done. */
    public function finishOk(): void
    {
        @unlink($this->pendingFile());
    }

    /** AfterUpdate failed: put the old version back and leave a note for the Updates page. */
    public function finishFailed(Throwable $error): void
    {
        $pending = $this->pending();
        @unlink($this->pendingFile());

        if ($pending === null) {
            return;
        }

        $this->backups->restore($pending['backup']);
        file_put_contents($this->updatesFolder . '/failed.json', json_encode([
            'from' => $pending['from'], 'to' => $pending['to'], 'error' => $error->getMessage(), 'at' => gmdate('c'),
        ]) . "\n");
    }

    /** @return array{from: string, to: string, error: string, at: string}|null the last failed update, shown once */
    public function takeFailure(): ?array
    {
        $file = $this->updatesFolder . '/failed.json';
        $data = json_decode((string) @file_get_contents($file), true);
        @unlink($file);

        return is_array($data) ? ['from' => (string) ($data['from'] ?? ''), 'to' => (string) ($data['to'] ?? ''), 'error' => (string) ($data['error'] ?? ''), 'at' => (string) ($data['at'] ?? '')] : null;
    }

    /** A fresh work folder in data/updates/ for unpacking. */
    public function workFolder(): string
    {
        return $this->updatesFolder . '/work-' . bin2hex(random_bytes(4));
    }

    /** Removes work folders and downloaded zips. */
    public function cleanUp(): void
    {
        foreach (glob($this->updatesFolder . '/work-*') ?: [] as $folder) {
            Files::removeTree($folder);
        }

        foreach (glob($this->updatesFolder . '/download-*.zip') ?: [] as $zip) {
            @unlink($zip);
        }
    }

    /** @return list<string> */
    private function manifestFiles(string $file): array
    {
        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) && is_array($data['files'] ?? null) ? array_map('strval', array_keys($data['files'])) : [];
    }

    private function pendingFile(): string
    {
        return $this->updatesFolder . '/pending.json';
    }
}
