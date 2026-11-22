<?php

declare(strict_types=1);

namespace Bioly\Repository;

use Bioly\Support\Clock;

/**
 * Rows of the media library: images ('content') and own icons ('icon').
 * The files themselves live in data/uploads (see MediaService).
 */
final class MediaRepository extends Repository
{
    /** @return list<array<string, mixed>> newest first */
    public function byUsage(string $usage): array
    {
        return $this->all('SELECT * FROM media WHERE usage = ? ORDER BY created_at DESC, id DESC', [$usage]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM media WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByFilename(string $filename): ?array
    {
        return $this->one('SELECT * FROM media WHERE filename = ?', [$filename]);
    }

    /**
     * @param array{filename: string, original_name: string, mime: string, size: int, width?: int|null, height?: int|null, usage: string, label?: string|null, color?: string|null, has_alpha?: int} $row
     */
    public function add(array $row): int
    {
        return $this->insert('media', [
            'filename' => $row['filename'],
            'original_name' => mb_substr($row['original_name'], 0, 200),
            'mime' => $row['mime'],
            'size' => $row['size'],
            'width' => $row['width'] ?? null,
            'height' => $row['height'] ?? null,
            'usage' => $row['usage'],
            'label' => $row['label'] ?? null,
            'color' => $row['color'] ?? null,
            'has_alpha' => !empty($row['has_alpha']) ? 1 : 0,
            'alt_text' => null,
            'created_at' => Clock::now(),
        ]);
    }

    /** @param array{label?: ?string, alt_text?: ?string, color?: ?string, has_alpha?: int} $values */
    public function change(int $id, array $values): void
    {
        if ($values !== []) {
            $this->updateById('media', $id, $values);
        }
    }

    public function remove(int $id): void
    {
        $this->run('DELETE FROM media WHERE id = ?', [$id]);
    }
}
