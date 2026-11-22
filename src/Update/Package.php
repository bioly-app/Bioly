<?php

declare(strict_types=1);

namespace Bioly\Update;

use RuntimeException;
use ZipArchive;

/**
 * A Bioly release zip, unpacked into a work folder and checked:
 * - every entry lies inside "bioly/" (no "..", no absolute paths)
 * - manifest.json is signed with the Bioly key (manifest.sig, see bin/release.php)
 * - every file matches its SHA-256 in the manifest, and there are no other files
 * Only then does the Updater copy anything.
 */
final class Package
{
    /** Signature and manifest themselves are not listed in the manifest. */
    private const META = ['manifest.json', 'manifest.sig'];

    /**
     * @param array<string, string> $files relative path => SHA-256
     */
    private function __construct(
        public readonly string $root,
        public readonly string $version,
        public readonly string $php,
        public readonly array $files,
        public readonly bool $signed,
    ) {}

    /**
     * @param bool $allowUnsigned only for the local test site (test zips from "make zip" are not signed)
     */
    public static function open(string $zipPath, string $workDir, string $publicKey, bool $allowUnsigned = false): self
    {
        if (!class_exists(ZipArchive::class)) {
            throw new UpdateException('update.error.no_zip');
        }

        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new UpdateException('update.error.not_zip');
        }

        // A backup zip in the wrong field: say where it belongs.
        if ($zip->locateName('bioly-backup/backup.json') !== false) {
            $zip->close();

            throw new UpdateException('update.error.is_backup');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if (!str_starts_with($name, 'bioly/') || str_contains($name, '..') || str_contains($name, '\\') || str_contains($name, "\0")) {
                    throw new UpdateException('update.error.not_bioly');
                }
            }

            if (!is_dir($workDir) && !mkdir($workDir, 0775, true)) {
                throw new RuntimeException('Could not create ' . $workDir);
            }

            if (!$zip->extractTo($workDir)) {
                throw new UpdateException('update.error.not_zip');
            }
        } finally {
            $zip->close();
        }

        $root = $workDir . '/bioly';
        $manifest = @file_get_contents($root . '/manifest.json');

        if ($manifest === false || !is_file($root . '/VERSION')) {
            throw new UpdateException('update.error.not_bioly');
        }

        $signature = base64_decode(trim((string) @file_get_contents($root . '/manifest.sig')), true);
        $key = base64_decode($publicKey, true);
        $signed = $signature !== false && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
            && is_string($key) && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            && sodium_crypto_sign_verify_detached($signature, $manifest, $key);

        if (!$signed && !$allowUnsigned) {
            throw new UpdateException('update.error.signature');
        }

        $data = json_decode($manifest, true);

        if (!is_array($data) || ($data['name'] ?? null) !== 'bioly' || !is_string($data['version'] ?? null) || !is_array($data['files'] ?? null)) {
            throw new UpdateException('update.error.not_bioly');
        }

        /** @var array<string, string> $files */
        $files = array_filter($data['files'], static fn($hash, $path): bool => is_string($hash) && is_string($path), ARRAY_FILTER_USE_BOTH);
        self::checkFiles($root, $files);

        return new self($root, $data['version'], is_string($data['php'] ?? null) ? $data['php'] : '8.3.0', $files, $signed);
    }

    /** @param array<string, string> $files */
    private static function checkFiles(string $root, array $files): void
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $path = substr($file->getPathname(), strlen($root) + 1);

            if (in_array($path, self::META, true)) {
                continue;
            }

            if (!isset($files[$path]) || !hash_equals($files[$path], (string) hash_file('sha256', $file->getPathname()))) {
                throw new UpdateException('update.error.changed');
            }

            $found[$path] = true;
        }

        if (count($found) !== count($files)) {
            throw new UpdateException('update.error.changed');
        }
    }
}
