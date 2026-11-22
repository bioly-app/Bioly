<?php

declare(strict_types=1);

namespace Bioly\Update;

use Bioly\Database\Connection;
use Bioly\Database\Migrator;
use Bioly\Service\MediaService;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Backups in data/backups/<date>-<version>/. Two kinds:
 *
 * - "update", made before every update: program files (files/) and the database, so a
 *   failed update can bring the old version back on its own. The newest KEEP stay.
 * - "manual", made by hand or uploaded: only the content, i.e. the database and the media
 *   library (uploads/). The program never changes in use and can always be installed
 *   again. These stay until the owner deletes them, can be downloaded as a zip and
 *   uploaded on another installation.
 *
 * Restoring a manual backup keeps what belongs to this installation: the account
 * (users, login data), the mail settings, the update settings and the address of the page.
 * Not in any backup: data/config.php with the secret key, cache, logs.
 */
final class Backups
{
    public const KEEP = 2;

    /** Tables that stay as they are when content comes back (account and login). */
    private const OWN_TABLES = ['users', 'login_attempts', 'tokens', 'rate_hits'];

    /** Settings that stay as they are when content comes back. */
    private const OWN_SETTINGS = ['smtp', 'update', 'install'];

    /** An uploaded backup may be at most this large (unpacked). */
    private const MAX_BYTES = 200 * 1024 * 1024;

    public function __construct(
        private readonly string $folder,
        private readonly string $root,
        private readonly PDO $pdo,
        private readonly string $uploads,
        private readonly string $migrations,
        private readonly string $version,
    ) {}

    /**
     * @param bool $manual by hand: database and media library; otherwise program files and database
     *
     * @return string the id (folder name) of the new backup
     */
    public function create(bool $manual = false): string
    {
        $id = gmdate('Ymd-His') . '-' . preg_replace('/[^0-9A-Za-z.-]/', '', $this->version);
        $path = $this->folder . '/' . $id;

        if (is_dir($path) || !@mkdir($path, 0775, true)) {
            throw new RuntimeException('Could not create ' . $path);
        }

        if ($manual) {
            foreach (is_dir($this->uploads) ? Files::program($this->uploads) : [] as $file) {
                Files::copy($this->uploads . '/' . $file, $path . '/uploads/' . $file);
            }
        } else {
            foreach (Files::program($this->root) as $file) {
                Files::copy($this->root . '/' . $file, $path . '/files/' . $file);
            }
        }

        // A consistent copy even while the database is open.
        $this->pdo->exec('VACUUM INTO ' . $this->pdo->quote($path . '/app.sqlite'));

        $this->writeInfo($path, $this->version, $manual ? 'manual' : 'update');
        $this->prune();

        return $id;
    }

    /**
     * Newest first.
     *
     * @return list<array{id: string, version: string, created_at: string, kind: string, size: int}>
     */
    public function all(): array
    {
        $list = [];

        foreach (glob($this->folder . '/*/backup.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            $path = dirname($file);

            if (is_array($data) && is_file($path . '/app.sqlite') && $this->exists(basename($path))) {
                $list[] = [
                    'id' => basename($path),
                    'version' => (string) ($data['version'] ?? '?'),
                    'created_at' => (string) ($data['created_at'] ?? ''),
                    'kind' => ($data['kind'] ?? 'update') === 'manual' ? 'manual' : 'update',
                    'size' => self::size($path),
                ];
            }
        }

        usort($list, static fn(array $a, array $b): int => strcmp($b['created_at'] . $b['id'], $a['created_at'] . $a['id']));

        return $list;
    }

    public function exists(string $id): bool
    {
        return preg_match('/^[0-9A-Za-z][0-9A-Za-z.-]*$/', $id) === 1 && is_file($this->folder . '/' . $id . '/backup.json');
    }

    /** @return array{id: string, version: string, created_at: string, kind: string, size: int}|null */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $backup) {
            if ($backup['id'] === $id) {
                return $backup;
            }
        }

        return null;
    }

    /**
     * Puts a backup back.
     * - update backup: program files and the whole database, as they were before the update
     * - manual backup: content (database without the account, media library); a backup of
     *   an older version is brought up to date, one of a newer version is refused
     */
    public function restore(string $id): void
    {
        $backup = $this->find($id) ?? throw new UpdateException('update.error.no_backup');
        $path = $this->folder . '/' . $id;

        if ($backup['kind'] === 'manual') {
            if (version_compare($backup['version'], $this->version, '>')) {
                throw new UpdateException('update.error.backup_newer');
            }

            $this->restoreDatabase($path . '/app.sqlite', true);
            $this->restoreMedia($path . '/uploads');
        } else {
            $saved = Files::program($path . '/files');

            foreach (array_diff(Files::program($this->root), $saved) as $extra) {
                @unlink($this->root . '/' . $extra);
                Files::removeEmptyFolders($this->root, $extra);
            }

            foreach ($saved as $file) {
                Files::copy($path . '/files/' . $file, $this->root . '/' . $file);
            }

            $this->restoreDatabase($path . '/app.sqlite', false);
        }

        // The next request brings database, media and page up to date (AfterUpdate):
        // SVGs from an uploaded backup are cleaned again, the page is built new.
        @unlink(dirname($this->uploads) . '/cache/version');
    }

    public function delete(string $id): void
    {
        if ($this->exists($id)) {
            Files::removeTree($this->folder . '/' . $id);
        }
    }

    /** A manual backup as one zip in $target, for downloading. */
    public function zip(string $id, string $target): void
    {
        if (!$this->exists($id) || !class_exists(ZipArchive::class)) {
            throw new UpdateException('update.error.no_backup');
        }

        $path = $this->folder . '/' . $id;
        $zip = new ZipArchive();

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create ' . $target);
        }

        foreach (Files::program($path) as $file) {
            $zip->addFile($path . '/' . $file, 'bioly-backup/' . $file);
        }

        $zip->close();
    }

    /**
     * Takes a downloaded backup zip (from this or another installation) into the list.
     * Only content is accepted: backup.json, app.sqlite and media files. No program files.
     *
     * @return string the id of the new backup
     */
    public function import(string $zipPath): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new UpdateException('update.error.no_zip');
        }

        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new UpdateException('update.error.not_backup');
        }

        $id = gmdate('Ymd-His') . '-import';
        $path = $this->folder . '/' . $id;

        // A Bioly update zip in the wrong field: say where it belongs.
        if ($zip->locateName('bioly/manifest.json') !== false) {
            $zip->close();

            throw new UpdateException('update.error.is_update');
        }

        try {
            $total = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = is_array($stat) ? (string) $stat['name'] : '';
                $total += is_array($stat) ? (int) $stat['size'] : 0;

                if (str_ends_with($name, '/')) {
                    continue;
                }

                $allowed = in_array($name, ['bioly-backup/backup.json', 'bioly-backup/app.sqlite'], true)
                    || (str_starts_with($name, 'bioly-backup/uploads/') && MediaService::validName(substr($name, 21)));

                if (!$allowed || $total > self::MAX_BYTES) {
                    throw new UpdateException('update.error.not_backup');
                }
            }

            $info = json_decode((string) $zip->getFromName('bioly-backup/backup.json'), true);
            $version = is_array($info) ? (string) ($info['version'] ?? '') : '';

            if (preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', $version) !== 1 || !str_starts_with((string) $zip->getFromName('bioly-backup/app.sqlite'), "SQLite format 3\0")) {
                throw new UpdateException('update.error.not_backup');
            }

            if (!@mkdir($path, 0775, true)) {
                throw new RuntimeException('Could not create ' . $path);
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if (!str_ends_with($name, '/') && $name !== 'bioly-backup/backup.json') {
                    $target = $path . '/' . substr($name, 13);
                    @mkdir(dirname($target), 0775, true);
                    file_put_contents($target, (string) $zip->getFromIndex($i));
                }
            }
        } catch (Throwable $exception) {
            Files::removeTree($path);

            throw $exception instanceof UpdateException ? $exception : new UpdateException('update.error.not_backup', 0, $exception);
        } finally {
            $zip->close();
        }

        // Check that it is a real Bioly database before it shows up in the list.
        try {
            $check = Connection::create($path . '/app.sqlite');
            self::rows($check, 'SELECT count(*) FROM settings', PDO::FETCH_COLUMN);
            self::rows($check, 'SELECT count(*) FROM blocks', PDO::FETCH_COLUMN);
            unset($check);
        } catch (Throwable) {
            Files::removeTree($path);

            throw new UpdateException('update.error.not_backup');
        }

        $this->writeInfo($path, $version, 'manual');

        return $id;
    }

    /**
     * Brings the tables of a backup database into the open database, inside SQLite (no file
     * is swapped under the open connection, which would break its WAL journal).
     *
     * @param bool $content only content: the backup is first brought to the current schema,
     *                      account tables and own settings stay as they are
     */
    private function restoreDatabase(string $backupDatabase, bool $content): void
    {
        $copy = $this->folder . '/restore-' . bin2hex(random_bytes(4)) . '.sqlite';
        Files::copy($backupDatabase, $copy);

        try {
            if ($content) {
                $migrator = new Migrator(Connection::create($copy), $this->migrations);
                $migrator->migrate();
                unset($migrator);
                gc_collect_cycles();
            }

            $keepTables = $content ? self::OWN_TABLES : [];

            $this->pdo->exec('PRAGMA foreign_keys = OFF');
            $this->pdo->exec('ATTACH DATABASE ' . $this->pdo->quote($copy) . ' AS backup');
            $this->pdo->beginTransaction();

            try {
                // Read before anything is dropped: needs both the open and the backup database.
                $keepSettings = $content ? $this->ownSettings() : [];

                $tables = self::rows($this->pdo, "SELECT name FROM main.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'", PDO::FETCH_COLUMN);

                foreach (array_diff($tables, $keepTables) as $table) {
                    $this->pdo->exec('DROP TABLE main."' . $table . '"');
                }

                $schema = self::rows($this->pdo, "SELECT type, name, tbl_name, sql FROM backup.sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY type = 'table' DESC", PDO::FETCH_ASSOC);

                foreach ($schema as $item) {
                    if (in_array($item['tbl_name'], $keepTables, true)) {
                        continue;
                    }

                    $this->pdo->exec((string) $item['sql']);

                    if ($item['type'] === 'table') {
                        $this->pdo->exec('INSERT INTO main."' . $item['name'] . '" SELECT * FROM backup."' . $item['name'] . '"');
                    }
                }

                foreach ($keepSettings as $key => $value) {
                    $statement = $this->pdo->prepare('INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at');
                    $statement->execute([$key, $value, gmdate('c')]);
                }

                $this->pdo->commit();
            } catch (Throwable $exception) {
                $this->pdo->rollBack();

                throw $exception;
            } finally {
                $this->pdo->exec('DETACH DATABASE backup');
                $this->pdo->exec('PRAGMA foreign_keys = ON');
            }
        } finally {
            @unlink($copy);
            @unlink($copy . '-wal');
            @unlink($copy . '-shm');
        }
    }

    /**
     * Settings of this installation that stay when content comes back: mail, update check,
     * install info, and inside "site" the address of the page (the rest of "site" comes
     * from the backup: name, bio, language, quicklinks ...).
     *
     * @return array<string, string> key => JSON value
     */
    private function ownSettings(): array
    {
        $current = self::rows($this->pdo, 'SELECT key, value FROM main.settings', PDO::FETCH_KEY_PAIR);
        $keep = array_map('strval', array_intersect_key($current, array_flip(self::OWN_SETTINGS)));
        $url = (json_decode((string) ($current['site'] ?? '{}'), true) ?: [])['url'] ?? null;

        if (is_string($url)) {
            $statement = $this->pdo->query("SELECT value FROM backup.settings WHERE key = 'site'");
            $site = json_decode((string) ($statement !== false ? $statement->fetchColumn() : '{}'), true);
            $site = is_array($site) ? $site : [];
            $site['url'] = $url;
            $keep['site'] = (string) json_encode($site, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $keep;
    }

    private function restoreMedia(string $saved): void
    {
        $media = is_dir($saved) ? Files::program($saved) : [];

        foreach (is_dir($this->uploads) ? array_diff(Files::program($this->uploads), $media) : [] as $extra) {
            @unlink($this->uploads . '/' . $extra);
        }

        foreach ($media as $file) {
            Files::copy($saved . '/' . $file, $this->uploads . '/' . $file);
        }
    }

    private function writeInfo(string $path, string $version, string $kind): void
    {
        file_put_contents($path . '/backup.json', json_encode(['version' => $version, 'created_at' => gmdate('c'), 'kind' => $kind], JSON_PRETTY_PRINT) . "\n");
    }

    /** Only backups from updates are deleted automatically; the ones made by hand stay. */
    private function prune(): void
    {
        $updates = array_values(array_filter($this->all(), static fn(array $backup): bool => $backup['kind'] === 'update'));

        foreach (array_slice($updates, self::KEEP) as $old) {
            Files::removeTree($this->folder . '/' . $old['id']);
        }
    }

    /** @return array<mixed> */
    private static function rows(PDO $pdo, string $sql, int $mode): array
    {
        $statement = $pdo->query($sql);

        if ($statement === false) {
            throw new RuntimeException('Query failed: ' . $sql);
        }

        return $statement->fetchAll($mode);
    }

    private static function size(string $path): int
    {
        $bytes = 0;

        foreach (Files::program($path) as $file) {
            $bytes += (int) filesize($path . '/' . $file);
        }

        return $bytes;
    }
}
