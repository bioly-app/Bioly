<?php

declare(strict_types=1);

namespace Bioly\Service;

use Bioly\Repository\MediaRepository;
use Bioly\Support\SvgSanitizer;
use GdImage;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Uploads in data/uploads:
 * - images of the media library (re-encoded, at most IMAGE_MAX px, no metadata)
 * - own icons (SVG, cleaned with an allow list)
 * - older favicon uploads (favicon-*.svg, before 0.1.6; not part of the library)
 * Library files have a row in the media table. File names are random, so every new
 * upload gets a new URL and can be cached forever.
 */
final class MediaService
{
    public const IMAGE_MAX = 1600;
    public const SVG_MAX_BYTES = 100 * 1024;
    private const MAX_PIXELS = 40_000_000;
    private const NAME = '/^(avatar|favicon|image|icon)-[a-f0-9]{16}\.(webp|jpg|svg)$/';

    public function __construct(
        private readonly string $uploadPath,
        private readonly int $maxBytes,
        private readonly ?MediaRepository $library = null,
    ) {}

    /**
     * Adds an image to the library.
     *
     * @return array{file: string, id: int}|array{error: string}
     */
    public function storeImage(UploadedFileInterface $upload): array
    {
        $content = $this->read($upload, $this->maxBytes);

        if (is_array($content)) {
            return $content;
        }

        $info = @getimagesizefromstring($content);

        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return ['error' => 'upload.avatar.type'];
        }

        if ($info[0] * $info[1] > self::MAX_PIXELS || !function_exists('imagecreatefromstring')) {
            return ['error' => 'upload.avatar.too_large'];
        }

        $image = @imagecreatefromstring($content);

        if (!$image instanceof GdImage) {
            return ['error' => 'upload.avatar.type'];
        }

        // Palette PNGs mark transparency with one color index; as true color it becomes a real alpha channel.
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);
        }

        $useWebp = function_exists('imagewebp');
        $image = $this->fit($this->orient($image, $content, $info[2]), self::IMAGE_MAX, $useWebp);
        $name = 'image-' . bin2hex(random_bytes(8)) . ($useWebp ? '.webp' : '.jpg');
        $this->ensureFolder();

        // Re-encoding drops every metadata block (EXIF, GPS, comments) of the original.
        $ok = $useWebp ? imagewebp($image, $this->path($name), 85) : imagejpeg($image, $this->path($name), 85);

        if (!$ok) {
            return ['error' => 'upload.failed'];
        }

        $alpha = $useWebp && self::hasAlpha($image);
        $id = $this->library?->add([
            'filename' => $name,
            'original_name' => $upload->getClientFilename() ?? $name,
            'mime' => $useWebp ? 'image/webp' : 'image/jpeg',
            'size' => (int) filesize($this->path($name)),
            'width' => imagesx($image),
            'height' => imagesy($image),
            'usage' => 'content',
            'label' => self::stem($upload->getClientFilename() ?? ''),
            'color' => $alpha ? null : self::averageColor($image),
            'has_alpha' => $alpha ? 1 : 0,
        ]);

        return ['file' => $name, 'id' => (int) $id];
    }

    /**
     * Adds an own icon. With a palette color it is drawn in one color and follows the
     * icon style like the library icons; without (null) it keeps its own colors.
     *
     * @return array{file: string, id: int}|array{error: string}
     */
    public function storeIcon(UploadedFileInterface $upload, ?string $color, string $label = ''): array
    {
        $content = $this->read($upload, self::SVG_MAX_BYTES);

        if (is_array($content)) {
            return $content['error'] === 'upload.too_large' ? ['error' => 'upload.favicon.too_large'] : $content;
        }

        $svg = SvgSanitizer::sanitize($content);

        if ($svg === null) {
            return ['error' => 'upload.favicon.invalid'];
        }

        $name = 'icon-' . bin2hex(random_bytes(8)) . '.svg';
        $this->ensureFolder();

        if (file_put_contents($this->path($name), $svg, LOCK_EX) === false) {
            return ['error' => 'upload.failed'];
        }

        $id = $this->library?->add([
            'filename' => $name,
            'original_name' => $upload->getClientFilename() ?? $name,
            'mime' => 'image/svg+xml',
            'size' => strlen($svg),
            'usage' => 'icon',
            'label' => trim($label) !== '' ? mb_substr(trim($label), 0, 40) : self::stem($upload->getClientFilename() ?? 'Icon'),
            'color' => $color,
        ]);

        return ['file' => $name, 'id' => (int) $id];
    }

    /**
     * Library rows of one kind. Only reads.
     *
     * @return list<array<string, mixed>>
     */
    public function library(string $usage): array
    {
        return $this->library?->byUsage($usage) ?? [];
    }

    /**
     * Catches up rows from older versions, once after an update (AfterUpdate):
     * header images from before 0.1.3 had no row, images from before 0.1.4 have
     * neither average color nor transparency flag.
     */
    public function backfill(): void
    {
        if ($this->library === null) {
            return;
        }

        foreach (glob($this->uploadPath . '/avatar-*') ?: [] as $file) {
            $name = basename($file);

            if (self::validName($name) && $this->library->findByFilename($name) === null) {
                $size = @getimagesize($file);
                $image = @imagecreatefromstring((string) file_get_contents($file));
                $this->library->add([
                    'filename' => $name,
                    'original_name' => $name,
                    'mime' => str_ends_with($name, '.webp') ? 'image/webp' : 'image/jpeg',
                    'size' => (int) filesize($file),
                    'width' => $size[0] ?? null,
                    'height' => $size[1] ?? null,
                    'usage' => 'content',
                    'label' => 'Header',
                    'color' => $image instanceof GdImage ? self::averageColor($image) : null,
                ]);
            }
        }

        foreach ($this->library->byUsage('content') as $row) {
            if ($row['color'] === null && (int) ($row['has_alpha'] ?? 0) === 0 && $this->exists((string) $row['filename'])) {
                $image = @imagecreatefromstring((string) $this->contents((string) $row['filename']));

                if ($image instanceof GdImage) {
                    $this->library->change((int) $row['id'], self::hasAlpha($image) ? ['has_alpha' => 1] : ['color' => self::averageColor($image)]);
                }
            }
        }
    }

    /**
     * Average color of an image as #RRGGBB. Shown while the image loads or when it is
     * missing, so an image never leaves a hole in the layout.
     */
    public static function averageColor(GdImage $image): string
    {
        $dot = imagecreatetruecolor(1, 1);
        imagecopyresampled($dot, $image, 0, 0, 0, 0, 1, 1, imagesx($image), imagesy($image));
        $rgb = imagecolorat($dot, 0, 0);

        return sprintf('#%02X%02X%02X', ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
    }

    /**
     * Whether an image has (partly) transparent pixels, checked on a small copy.
     */
    public static function hasAlpha(GdImage $image): bool
    {
        if (!imageistruecolor($image)) {
            if (imagecolortransparent($image) === -1) {
                return false;
            }

            imagepalettetotruecolor($image);
        }

        $size = 48;
        $small = imagecreatetruecolor($size, $size);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $size, $size, imagesx($image), imagesy($image));

        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y < $size; $y++) {
                if (((imagecolorat($small, $x, $y) >> 24) & 0x7F) > 8) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Removes a library file and its row. Whether it is still used is checked by the caller. */
    public function delete(int $id): bool
    {
        $row = $this->library?->find($id);

        if ($row === null || !self::validName((string) $row['filename'])) {
            return false;
        }

        @unlink($this->path((string) $row['filename']));
        $this->library->remove($id);

        return true;
    }

    private static function stem(string $filename): string
    {
        $stem = trim((string) preg_replace('/[_-]+/', ' ', pathinfo($filename, PATHINFO_FILENAME)));

        return mb_substr($stem !== '' ? $stem : 'Bild', 0, 60);
    }

    /** Runs every stored SVG through the current SvgSanitizer again; drops nothing else. */
    public function resanitizeSvgs(): void
    {
        foreach (glob($this->uploadPath . '/*.svg') ?: [] as $file) {
            $name = basename($file);
            $clean = self::validName($name) ? SvgSanitizer::sanitize((string) file_get_contents($file)) : null;

            if ($clean !== null) {
                file_put_contents($file, $clean, LOCK_EX);
            }
        }
    }

    public function exists(string $name): bool
    {
        return self::validName($name) && is_file($this->path($name));
    }

    /**
     * A file name Bioly gave itself, optionally of certain kinds only.
     * Images (avatar, image) are always webp or jpg, favicons and icons always svg.
     *
     * @param list<string> $kinds avatar, favicon, image, icon; empty means any
     */
    public static function validName(string $name, array $kinds = []): bool
    {
        return preg_match(self::NAME, $name, $match) === 1 && ($kinds === [] || in_array($match[1], $kinds, true));
    }

    public function contents(string $name): ?string
    {
        return $this->exists($name) ? (string) file_get_contents($this->path($name)) : null;
    }

    public static function contentType(string $name): string
    {
        return match (pathinfo($name, PATHINFO_EXTENSION)) {
            'webp' => 'image/webp',
            'jpg' => 'image/jpeg',
            default => 'image/svg+xml',
        };
    }

    /**
     * Deletes favicon uploads that are neither in use nor fresh. Fresh files (one day) are kept,
     * because an unpublished draft in another tab may still point to them.
     * Library files (images, icons) are only ever deleted by the user.
     *
     * @param list<string> $inUse
     */
    public function cleanup(array $inUse): void
    {
        foreach (glob($this->uploadPath . '/favicon-*') ?: [] as $file) {
            $name = basename($file);

            if (self::validName($name) && !in_array($name, $inUse, true) && filemtime($file) < time() - 86400) {
                @unlink($file);
            }
        }
    }

    private function path(string $name): string
    {
        return $this->uploadPath . '/' . $name;
    }

    private function ensureFolder(): void
    {
        if (!is_dir($this->uploadPath)) {
            mkdir($this->uploadPath, 0775, true);
        }
    }

    /** @return string|array{error: string} */
    private function read(UploadedFileInterface $upload, int $maxBytes): string|array
    {
        if ($upload->getError() === UPLOAD_ERR_INI_SIZE || $upload->getError() === UPLOAD_ERR_FORM_SIZE) {
            return ['error' => 'upload.too_large'];
        }

        if ($upload->getError() !== UPLOAD_ERR_OK) {
            return ['error' => 'upload.failed'];
        }

        if (($upload->getSize() ?? 0) > $maxBytes) {
            return ['error' => 'upload.too_large'];
        }

        $content = (string) $upload->getStream();

        if (strlen($content) > $maxBytes) {
            return ['error' => 'upload.too_large'];
        }

        return $content === '' ? ['error' => 'upload.failed'] : $content;
    }

    /** Phone photos are often stored sideways with an EXIF hint; turn them upright. */
    private function orient(GdImage $image, string $content, int $type): GdImage
    {
        if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($content));
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    /** Scales the image so the longer side is at most $max (never up). */
    private function fit(GdImage $image, int $max, bool $transparent): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $max / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($w, $h);
        // WebP keeps transparency; JPEG has none, so transparent parts become white.
        imagealphablending($target, !$transparent);
        imagesavealpha($target, $transparent);
        $fill = $transparent ? imagecolorallocatealpha($target, 0, 0, 0, 127) : imagecolorallocate($target, 255, 255, 255);

        if ($fill !== false) {
            imagefill($target, 0, 0, $fill);
        }
        imagecopyresampled($target, $image, 0, 0, 0, 0, $w, $h, $width, $height);

        return $target;
    }
}
