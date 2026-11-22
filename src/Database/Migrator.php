<?php

declare(strict_types=1);

namespace Bioly\Database;

use PDO;
use RuntimeException;
use Throwable;

final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsPath,
    ) {}

    /**
     * Runs all migrations that were not applied yet.
     *
     * @return list<string> applied migration versions
     */
    public function migrate(): array
    {
        $this->ensureTable();
        $done = [];

        foreach ($this->pending() as $version => $file) {
            $this->apply($version, $file);
            $done[] = $version;
        }

        return $done;
    }

    public function hasPending(): bool
    {
        $this->ensureTable();

        return $this->pending() !== [];
    }

    /** @return array<string, string> */
    private function pending(): array
    {
        $statement = $this->pdo->query('SELECT version FROM schema_migrations') ?: throw new RuntimeException('Could not read the applied migrations.');
        $applied = $statement->fetchAll(PDO::FETCH_COLUMN);

        return array_diff_key($this->available(), array_flip($applied));
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)',
        );
    }

    /** @return array<string, string> version => file path, sorted */
    private function available(): array
    {
        $migrations = [];

        foreach (glob($this->migrationsPath . '/*.sql') ?: [] as $file) {
            $migrations[basename($file, '.sql')] = $file;
        }

        ksort($migrations, SORT_NATURAL);

        return $migrations;
    }

    private function apply(string $version, string $file): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->pdo->exec((string) file_get_contents($file));
            $this->pdo
                ->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)')
                ->execute([$version, gmdate('Y-m-d H:i:s')]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }
}
