<?php

declare(strict_types=1);

namespace Bioly\Service;

use Bioly\Repository\BlockRepository;
use Bioly\Repository\SettingsRepository;

/**
 * Where a library file is used. Used files cannot be deleted, so the page never
 * points to something that is gone.
 */
final class MediaUsage
{
    public function __construct(
        private readonly BlockRepository $blocks,
        private readonly SettingsRepository $settings,
    ) {}

    /** Icon id of an own icon file: icon-<hex>.svg => own:<hex> */
    public static function iconId(string $filename): string
    {
        return 'own:' . substr(basename($filename, '.svg'), 5);
    }

    /**
     * @param array<string, mixed> $row media row
     * @return list<array{key: string, count: int}> translation key and count, empty when unused
     */
    public function of(array $row): array
    {
        $filename = (string) $row['filename'];
        $uses = [];

        if ($row['usage'] === 'content') {
            if (($this->settings->getArray('site')['avatar'] ?? null) === $filename) {
                $uses[] = ['key' => 'media.used.header', 'count' => 1];
            }

            $background = $this->settings->getArray('theme')['background'] ?? [];

            if (is_array($background) && ($background['type'] ?? null) === 'image' && ($background['image'] ?? null) === $filename) {
                $uses[] = ['key' => 'media.used.background', 'count' => 1];
            }

            $images = $this->countImage($this->blocks->tree(), $filename);

            if ($images > 0) {
                $uses[] = ['key' => 'media.used.images', 'count' => $images];
            }

            return $uses;
        }

        $id = self::iconId($filename);
        $links = $this->countIcon($this->blocks->tree(), $id);

        if ($links > 0) {
            $uses[] = ['key' => 'media.used.links', 'count' => $links];
        }

        $favicon = $this->settings->getArray('theme')['favicon'] ?? [];

        $asIcon = ($favicon['type'] ?? null) === 'icon' && ($favicon['icon'] ?? null) === $id;
        $asFile = ($favicon['type'] ?? null) === 'upload' && ($favicon['file'] ?? null) === $filename;

        if (is_array($favicon) && ($asIcon || $asFile)) {
            $uses[] = ['key' => 'media.used.favicon', 'count' => 1];
        }

        return $uses;
    }

    /**
     * Image modules that show the file (hidden ones included).
     *
     * @param list<array<string, mixed>> $blocks
     */
    private function countImage(array $blocks, string $filename): int
    {
        $count = 0;

        foreach ($blocks as $block) {
            if (($block['type'] ?? '') !== 'image') {
                continue;
            }

            $images = json_decode((string) ($block['data']['images'] ?? ''), true);
            $files = is_array($images) ? array_column($images, 'file') : [];
            $count += in_array($filename, $files, true) ? 1 : 0;
        }

        return $count;
    }

    /** @param list<array<string, mixed>> $blocks */
    private function countIcon(array $blocks, string $id): int
    {
        $count = 0;

        foreach ($blocks as $block) {
            $count += (($block['data']['icon'] ?? null) === $id ? 1 : 0) + $this->countIcon($block['children'] ?? [], $id);
        }

        return $count;
    }
}
