<?php

declare(strict_types=1);

namespace Bioly\Repository;

use PDO;

abstract class Repository
{
    public function __construct(protected readonly PDO $pdo) {}

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    protected function one(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    protected function all(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = [];

        while (is_array($row = $statement->fetch(PDO::FETCH_ASSOC))) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param list<mixed> $params */
    protected function run(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    /** @param array<string, mixed> $row */
    protected function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        );
        $this->run($sql, array_values($row));

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $values */
    protected function updateById(string $table, int $id, array $values): void
    {
        $set = implode(', ', array_map(static fn(string $column): string => $column . ' = ?', array_keys($values)));
        $this->run(sprintf('UPDATE %s SET %s WHERE id = ?', $table, $set), [...array_values($values), $id]);
    }
}
