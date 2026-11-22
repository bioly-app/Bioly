<?php

declare(strict_types=1);

namespace Bioly\Modules\Image;

use Bioly\Module\AbstractModule;
use Bioly\Module\RenderContext;

/**
 * One image or several as a slider. The images come from the media library, with their
 * alt text and average color; each one can link somewhere.
 */
final class ImageModule extends AbstractModule
{
    public const RATIOS = ['1-1', '4-3', '16-9'];

    /** Seconds between two images when the slider advances by itself. */
    public const INTERVALS = ['3', '5', '8', '12'];

    public function type(): string
    {
        return 'image';
    }

    public function fields(): array
    {
        return [
            ['name' => 'images', 'type' => 'images', 'required' => true, 'group' => 'images'],
            ['name' => 'ratio', 'type' => 'select', 'options' => self::RATIOS, 'default' => '4-3', 'group' => 'look'],
            ['name' => 'autoplay', 'type' => 'toggle', 'default' => false, 'group' => 'autoplay'],
            ['name' => 'interval', 'type' => 'select', 'options' => self::INTERVALS, 'default' => '5', 'group' => 'autoplay'],
            ['name' => 'title', 'type' => 'text', 'max' => 80, 'group' => 'a11y'],
        ];
    }

    public function view(array $data, array $children, RenderContext $context): array
    {
        $images = [];

        foreach (json_decode((string) $data['images'], true) ?: [] as $image) {
            $row = $context->media((string) $image['file']);

            // A file that was removed in the meantime simply drops out.
            if ($row === null) {
                continue;
            }

            $transparent = !empty($row['has_alpha']);
            $images[] = [
                'url' => '/media/' . $row['filename'],
                'transparent' => $transparent,
                'alt' => trim((string) ($row['alt_text'] ?? '')),
                'color' => !$transparent && is_string($row['color'] ?? null) && preg_match('/^#[0-9A-F]{6}$/', $row['color']) === 1 ? $row['color'] : '',
                'width' => (int) ($row['width'] ?? 0),
                'height' => (int) ($row['height'] ?? 0),
                'link' => (string) ($image['url'] ?? ''),
            ];
        }

        return [
            'title' => $data['title'] ?? '',
            'ratio' => in_array($data['ratio'] ?? '', self::RATIOS, true) ? $data['ratio'] : '4-3',
            'autoplay' => !empty($data['autoplay']) && count($images) > 1,
            'interval' => (int) (in_array($data['interval'] ?? '', self::INTERVALS, true) ? $data['interval'] : '5'),
            'images' => $images,
            // Transparent images (logos) show the page background: no card behind them.
            'clear' => in_array(true, array_column($images, 'transparent'), true),
        ];
    }

    public function sortOrder(): int
    {
        return 60;
    }
}
