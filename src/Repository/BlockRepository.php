<?php

declare(strict_types=1);

namespace Bioly\Repository;

use Bioly\Support\Clock;

final class BlockRepository extends Repository
{
    /**
     * All blocks as a tree: top level in order, children under "children".
     *
     * @return list<array<string, mixed>>
     */
    public function tree(bool $onlyActive = false): array
    {
        $rows = $this->all('SELECT * FROM blocks ORDER BY position, id');
        $byParent = [];

        foreach ($rows as $row) {
            if ($onlyActive && !$row['is_active']) {
                continue;
            }

            $block = $this->hydrate($row);
            $byParent[$row['parent_id'] ?? 0][] = $block;
        }

        $attach = static function (array $block) use (&$attach, $byParent): array {
            $block['children'] = array_map($attach, $byParent[$block['id']] ?? []);

            return $block;
        };

        return array_map($attach, $byParent[0] ?? []);
    }

    /** @return array<string, mixed>|null */
    public function findActive(int $id): ?array
    {
        $row = $this->one('SELECT * FROM blocks WHERE id = ? AND is_active = 1', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /** @return list<array<string, mixed>> */
    public function activeChildren(int $parentId): array
    {
        return array_map(
            $this->hydrate(...),
            $this->all('SELECT * FROM blocks WHERE parent_id = ? AND is_active = 1 ORDER BY position, id', [$parentId]),
        );
    }

    /**
     * Replaces all blocks with the given tree. Runs inside the caller's transaction.
     *
     * @param list<array{type: string, is_active: bool, data: array<string, mixed>, children?: list<array<string, mixed>>}> $tree
     */
    public function replaceAll(array $tree): void
    {
        $this->run('DELETE FROM blocks');
        $this->insertTree($tree, null);
    }

    /** @param list<array<string, mixed>> $blocks */
    private function insertTree(array $blocks, ?int $parentId): void
    {
        $now = Clock::now();

        foreach ($blocks as $position => $block) {
            $id = $this->insert('blocks', [
                'parent_id' => $parentId,
                'type' => $block['type'],
                'position' => $position,
                'is_active' => empty($block['is_active']) ? 0 : 1,
                'data' => json_encode($block['data'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->insertTree($block['children'] ?? [], $id);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
            'type' => $row['type'],
            'is_active' => (bool) $row['is_active'],
            'data' => json_decode($row['data'], true) ?: [],
        ];
    }
}
