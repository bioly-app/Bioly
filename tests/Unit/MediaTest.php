<?php

declare(strict_types=1);

namespace Bioly\Tests\Unit;

use Bioly\Database\Connection;
use Bioly\Database\Migrator;
use Bioly\Icon\IconRegistry;
use Bioly\Repository\BlockRepository;
use Bioly\Repository\MediaRepository;
use Bioly\Repository\SettingsRepository;
use Bioly\Service\FaviconService;
use Bioly\Service\MediaService;
use Bioly\Service\MediaUsage;
use Bioly\Support\SvgSanitizer;
use Bioly\Theme\Theme;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

final class MediaTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/bioly-media-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->folder . '/*') ?: []);
        @rmdir($this->folder);
    }

    public function testSanitizerKeepsShapesAndDropsEverythingDangerous(): void
    {
        $svg = <<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 32 32" onload="alert(1)">
              <script>alert(2)</script><style>*{}</style>
              <defs><linearGradient id="g"><stop offset="0" stop-color="#f00"/></linearGradient></defs>
              <rect width="32" height="32" fill="url(#g)"/>
              <circle cx="16" cy="16" r="8" fill="url(https://evil.example/a#b)" onclick="alert(3)"/>
              <a xlink:href="javascript:alert(4)"><path d="M0 0h4v4z"/></a>
              <foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>
              <image href="https://evil.example/x.png"/>
            </svg>
            SVG;
        $clean = (string) SvgSanitizer::sanitize($svg);

        self::assertMatchesRegularExpression('/<rect width="32" height="32" fill="url\(#(s[0-9a-f]{8}-)g\)"\/>/', $clean);
        self::assertMatchesRegularExpression('/id="s[0-9a-f]{8}-g"/', $clean);
        self::assertStringContainsString('<circle cx="16" cy="16" r="8"/>', $clean);

        foreach (['script', 'style', 'onload', 'onclick', 'javascript', 'evil.example', 'foreignObject', 'image', 'xlink', '<a'] as $bad) {
            self::assertStringNotContainsString($bad, $clean);
        }
    }

    public function testSanitizerGivesEachFileItsOwnIds(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><defs><linearGradient id="a"/></defs>'
            . '<path d="M0 0h4v4z" fill="url(#a)" stroke="url(#other)"/></svg>';
        $first = (string) SvgSanitizer::sanitize($svg);
        $second = (string) SvgSanitizer::sanitize($svg);

        preg_match('/id="([^"]+)"/', $first, $id);
        self::assertStringContainsString('fill="url(#' . $id[1] . ')"', $first);
        self::assertStringContainsString('stroke="url(#other)"', $first, 'unknown references stay as they are');
        self::assertNotSame($first, $second);
    }

    public function testSanitizerRejectsNonSvg(): void
    {
        self::assertNull(SvgSanitizer::sanitize('<html><body>x</body></html>'));
        self::assertNull(SvgSanitizer::sanitize('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>'));
        self::assertNull(SvgSanitizer::sanitize('<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>'), 'no size at all');
        self::assertNull(SvgSanitizer::sanitize('not xml'));
    }

    public function testImageKeepsItsShapeAndIsReencoded(): void
    {
        $jpeg = $this->jpeg(800, 400);
        $media = new MediaService($this->folder, 4 * 1024 * 1024);
        $result = $media->storeImage($this->upload($jpeg, 'photo.jpg'));

        self::assertArrayHasKey('file', $result);
        self::assertStringStartsWith('image-', $result['file']);
        self::assertTrue($media->exists($result['file']));
        $size = getimagesizefromstring((string) $media->contents($result['file']));
        self::assertSame([800, 400], [$size[0] ?? 0, $size[1] ?? 0]);

        // Large images get at most IMAGE_MAX on the longer side, never upscaled.
        $big = $media->storeImage($this->upload($this->jpeg(3200, 1000), 'wide.jpg'));
        $size = getimagesizefromstring((string) $media->contents((string) $big['file']));
        self::assertSame([MediaService::IMAGE_MAX, 500], [$size[0] ?? 0, $size[1] ?? 0]);

        self::assertSame(['error' => 'upload.avatar.type'], $media->storeImage($this->upload('<svg/>', 'x.png')));
        self::assertSame(['error' => 'upload.too_large'], (new MediaService($this->folder, 10))->storeImage($this->upload($jpeg, 'big.jpg')));
    }

    public function testLibraryOwnIconsAndUsage(): void
    {
        $pdo = Connection::create(':memory:');
        (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $repository = new MediaRepository($pdo);
        $media = new MediaService($this->folder, 4 * 1024 * 1024, $repository);

        $image = $media->storeImage($this->upload($this->jpeg(300, 200), 'mein_profil-bild.jpg'));
        $row = $repository->find((int) $image['id']);
        self::assertSame('mein profil bild', $row['label'] ?? null);
        self::assertSame([300, 200], [$row['width'] ?? 0, $row['height'] ?? 0]);
        self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', (string) ($row['color'] ?? ''));

        // Header images from before 0.1.3 have no row yet; the step after an update adopts them.
        file_put_contents($this->folder . '/avatar-0123456789abcdef.webp', 'x');
        self::assertCount(1, $media->library('content'), 'reading changes nothing');
        $media->backfill();
        self::assertCount(2, $media->library('content'));

        $colored = $media->storeIcon($this->upload('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h10v10z" fill="#ff0000" stroke="blue"/></svg>', 'stern.svg'), 'gruen');
        $own = $media->storeIcon($this->upload('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="5" fill="#ff0000"/></svg>', 'logo.svg'), null);
        self::assertSame(['error' => 'upload.favicon.invalid'], $media->storeIcon($this->upload('<b>x</b>', 'x.svg'), null));

        $root = dirname(__DIR__, 2);
        $icons = new IconRegistry($root . '/resources/icons', $root . '/resources/originals', $this->folder . '/icons.php', fn(): array => array_map(
            fn(array $r): array => [...$r, 'svg' => (string) $media->contents((string) $r['filename'])],
            $repository->byUsage('icon'),
        ));
        $coloredId = MediaUsage::iconId((string) $colored['file']);
        $ownId = MediaUsage::iconId((string) $own['file']);

        $icon = $icons->get($coloredId);
        self::assertSame('gruen', $icon['color'] ?? null);
        self::assertSame('own', $icon['source'] ?? null);
        self::assertStringNotContainsString('#ff0000', $icon['svg'] ?? '');
        self::assertStringContainsString('icon--gruen', $icons->html($coloredId));
        self::assertNull($icons->get($ownId)['color'] ?? null);
        self::assertStringContainsString('#ff0000', $icons->html($ownId));
        self::assertStringContainsString('icon--original', $icons->html($ownId));

        // Own icons work as favicon: nested svg on the surface tile.
        $theme = new Theme($icons->palette());
        $favicons = new FaviconService($icons, $media, $theme);
        $favicon = $favicons->svg($theme->normalize(['favicon' => ['type' => 'icon', 'icon' => $ownId]]), '');
        self::assertStringContainsString('<svg x="6" y="6" width="20" height="20"', $favicon);
        self::assertStringContainsString('#ff0000', $favicon);

        // "Own SVG" from the media library: the icon as it is, one-color ones in the icon style.
        $asIs = $favicons->svg($theme->normalize(['favicon' => ['type' => 'upload', 'file' => $own['file']]]), '');
        self::assertStringStartsWith('<svg', $asIs);
        self::assertStringNotContainsString('x="6"', $asIs);
        self::assertStringContainsString('#ff0000', $asIs);
        $tintedOwn = $favicons->svg($theme->normalize(['favicon' => ['type' => 'upload', 'file' => $colored['file']]]), '');
        self::assertStringContainsString($theme->iconLook($theme->normalize([]))['palette']['gruen'], $tintedOwn);
        self::assertStringNotContainsString('currentColor', $tintedOwn);

        // Usage: an icon in a multilink child and as favicon, the image in the header.
        $settings = new SettingsRepository($pdo);
        $blocks = new BlockRepository($pdo);
        $blocks->replaceAll([
            ['key' => 'g', 'type' => 'image', 'is_active' => false, 'data' => ['images' => json_encode([['file' => $image['file'], 'url' => '']])], 'children' => []],
            ['key' => 'a', 'type' => 'multilink', 'is_active' => false, 'data' => ['title' => 'x'], 'children' => [
                ['key' => 'b', 'type' => 'link', 'is_active' => true, 'data' => ['title' => 'y', 'url' => 'https://example.com', 'icon' => $coloredId], 'children' => []],
            ]],
        ]);
        $settings->set('site', ['avatar' => $image['file']]);
        $settings->set('theme', ['favicon' => ['type' => 'icon', 'icon' => $coloredId]]);
        $usage = new MediaUsage($blocks, $settings);

        self::assertSame(
            [['key' => 'media.used.header', 'count' => 1], ['key' => 'media.used.images', 'count' => 1]],
            $usage->of((array) $repository->find((int) $image['id'])),
        );
        self::assertSame(
            [['key' => 'media.used.links', 'count' => 1], ['key' => 'media.used.favicon', 'count' => 1]],
            $usage->of((array) $repository->find((int) $colored['id'])),
        );
        self::assertSame([], $usage->of((array) $repository->find((int) $own['id'])));
        $settings->set('theme', ['favicon' => ['type' => 'upload', 'file' => $own['file']]]);
        self::assertSame([['key' => 'media.used.favicon', 'count' => 1]], $usage->of((array) $repository->find((int) $own['id'])));

        self::assertTrue($media->delete((int) $own['id']));
        self::assertFalse($media->exists((string) $own['file']));
        self::assertNull($repository->find((int) $own['id']));
    }

    public function testTransparentImagesAreRecognized(): void
    {
        $clear = imagecreatetruecolor(40, 40);
        imagealphablending($clear, false);
        imagesavealpha($clear, true);
        imagefill($clear, 0, 0, imagecolorallocatealpha($clear, 0, 0, 0, 127));
        imagefilledrectangle($clear, 10, 10, 30, 30, imagecolorallocate($clear, 200, 0, 0));

        self::assertTrue(MediaService::hasAlpha($clear));
        self::assertFalse(MediaService::hasAlpha(imagecreatetruecolor(40, 40)));
        self::assertSame('#000000', MediaService::averageColor(imagecreatetruecolor(4, 4)));
    }

    public function testMonochromeTurnsColorsIntoCurrentColor(): void
    {
        $svg = (string) SvgSanitizer::monochrome('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><path d="M0 0h4" fill="#123456" stroke="red"/><rect width="4" height="4" fill="none" stop-color="url(#g)"/></svg>');

        self::assertStringNotContainsString('#123456', $svg);
        self::assertStringNotContainsString('red', $svg);
        self::assertStringContainsString('fill="none"', $svg);
        self::assertStringContainsString('fill="currentColor"', $svg);
        self::assertStringContainsString('stroke="currentColor"', $svg);
    }

    public function testFaviconRendering(): void
    {
        $media = new MediaService($this->folder, 1024 * 1024);
        // Favicon files uploaded before 0.1.6 still work.
        $stored = ['file' => 'favicon-0123456789abcdef.svg'];
        @mkdir($this->folder, 0775, true);
        file_put_contents($this->folder . '/' . $stored['file'], '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><rect width="8" height="8"/></svg>');

        $root = dirname(__DIR__, 2);
        $icons = new IconRegistry($root . '/resources/icons', $root . '/resources/originals', $this->folder . '/icons.php');
        $theme = new Theme($icons->palette());
        $favicons = new FaviconService($icons, $media, $theme);

        $auto = $favicons->svg($theme->normalize([]), 'Bene');
        self::assertStringContainsString('>B</text>', $auto);

        // The icon favicon looks like an icon tile and follows the icon style.
        $look = $theme->iconLook($theme->normalize([]));
        $tinted = $favicons->svg($theme->normalize(['favicon' => ['type' => 'icon', 'icon' => 'std:heart']]), '');
        self::assertStringContainsString('fill="' . $look['surface'] . '"', $tinted);
        self::assertStringContainsString('stroke="' . $look['palette']['rot'] . '"', $tinted);
        self::assertStringNotContainsString('currentColor', $tinted);
        $mono = $favicons->svg($theme->normalize(['icon_style' => 'mono', 'favicon' => ['type' => 'icon', 'icon' => 'std:heart']]), '');
        self::assertStringContainsString('stroke="' . $look['text'] . '"', $mono);
        self::assertStringContainsString('fill-opacity="0.08"', $mono);
        $accent = $favicons->svg($theme->normalize(['icon_style' => 'accent', 'favicon' => ['type' => 'icon', 'icon' => 'std:heart']]), '');
        self::assertStringContainsString('stroke="' . $look['accent'] . '"', $accent);

        $upload = $favicons->svg($theme->normalize(['favicon' => ['type' => 'upload', 'file' => $stored['file']]]), '');
        self::assertStringContainsString('<rect width="8" height="8"/>', $upload);

        // Unknown or missing choices fall back to the automatic favicon.
        self::assertSame('auto', $theme->normalize(['favicon' => ['type' => 'upload', 'file' => '../../etc/passwd']])['favicon']['type']);
        self::assertSame('auto', $theme->normalize(['favicon' => ['type' => 'icon', 'icon' => 'orig:x']])['favicon']['type']);
    }

    private function jpeg(int $width, int $height): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor($width, $height));

        return (string) ob_get_clean();
    }

    private function upload(string $content, string $name): UploadedFile
    {
        return new UploadedFile((new StreamFactory())->createStream($content), $name, null, strlen($content), UPLOAD_ERR_OK);
    }
}
