<?php

declare(strict_types=1);

namespace Bioly\Update;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** File helpers for the updater: list, copy and delete program files, never data/. */
final class Files
{
    /**
     * Relative paths of all files under $root, without data/ and without folders a
     * development copy may have (.git). Sorted.
     *
     * @return list<string>
     */
    public static function program(string $root): array
    {
        $paths = [];
        $skip = static fn(string $path): bool => $path === 'data' || str_starts_with($path, 'data/') || $path === '.git' || str_starts_with($path, '.git/');
        $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            static fn(\SplFileInfo $file): bool => !$skip(substr($file->getPathname(), strlen($root) + 1)),
        );

        foreach (new RecursiveIteratorIterator($filter) as $file) {
            $paths[] = substr($file->getPathname(), strlen($root) + 1);
        }

        sort($paths);

        return $paths;
    }

    public static function copy(string $from, string $to): void
    {
        $folder = dirname($to);

        if (!is_dir($folder) && !@mkdir($folder, 0775, true) && !is_dir($folder)) {
            throw new RuntimeException('Could not create ' . $folder);
        }

        // Write next to the target, then rename: a file is never half written.
        $temp = $to . '.bioly-new';

        if (!@copy($from, $temp) || !@rename($temp, $to)) {
            @unlink($temp);

            throw new RuntimeException('Could not write ' . $to);
        }
    }

    public static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }

    /** Removes empty folders left behind after deleting files (bottom up, never $root itself). */
    public static function removeEmptyFolders(string $root, string $relativeFile): void
    {
        $folder = dirname($relativeFile);

        while ($folder !== '.' && $folder !== '' && !str_starts_with($folder, 'data')) {
            if (!@rmdir($root . '/' . $folder)) {
                return;
            }

            $folder = dirname($folder);
        }
    }
}
