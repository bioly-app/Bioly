<?php

declare(strict_types=1);

namespace Bioly\Page;

use Bioly\Icon\IconRegistry;
use Bioly\Module\ModuleRegistry;
use Bioly\Module\RenderContext;
use Bioly\Module\Validator;
use Bioly\Service\LegalText;
use Bioly\Service\MediaService;

/**
 * Turns profile, theme and blocks into the values for templates/frontend/page.twig.
 * Used for the published page and for the backend preview alike.
 */
final class PageBuilder
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly IconRegistry $icons,
        private readonly Validator $validator,
        /** @var (\Closure(string): ?array<string, mixed>)|null finds the media row of a file */
        private readonly ?\Closure $mediaRow = null,
    ) {}

    /** Time zone of the page (Settings > site), set by the publisher before building. */
    private string $timezone = 'Europe/Berlin';

    public function setTimezone(string $timezone): void
    {
        $this->timezone = $timezone;
    }

    /**
     * @param array<string, mixed> $profile name, bio
     * @param array<string, mixed> $theme normalized theme
     * @param list<array<string, mixed>> $blocks tree with key, type, is_active, data, children
     * @return array<string, mixed>
     */
    public function build(array $profile, array $theme, array $blocks): array
    {
        $groups = [];

        foreach ($blocks as $block) {
            $item = $this->item($block, $theme['icon_style']);

            if ($item === null) {
                continue;
            }

            $last = array_key_last($groups);

            if ($item['tile'] && $last !== null && $groups[$last]['kind'] === 'tiles') {
                $groups[$last]['items'][] = $item;
            } else {
                $groups[] = ['kind' => $item['tile'] ? 'tiles' : 'block', 'items' => [$item]];
            }
        }

        $name = trim((string) ($profile['name'] ?? ''));
        $avatar = (string) ($profile['avatar'] ?? '');
        $avatarRow = MediaService::validName($avatar) && $this->mediaRow !== null ? ($this->mediaRow)($avatar) : null;

        return [
            'site' => [
                'name' => $name,
                'bio' => (string) ($profile['bio'] ?? ''),
                'initials' => self::initials($name),
                'avatar_url' => MediaService::validName($avatar) ? '/media/' . $avatar : null,
                // Decorative images and images without alt text stay hidden from screen readers.
                'avatar_alt' => trim((string) ($avatarRow['alt_text'] ?? '')),
                // Transparent header images (logos) sit on the header color, not on a card surface.
                'avatar_clear' => $avatarRow !== null && !empty($avatarRow['has_alpha']),
                'quicklinks' => $this->quicklinks($profile['quicklinks'] ?? [], (string) $theme['icon_style']),
                // External imprint or privacy pages for the footer; missing keys mean the own page.
                'legal' => LegalText::externalLinks($profile['legal'] ?? null),
            ],
            'theme' => $theme,
            'groups' => $groups,
        ];
    }

    /**
     * Children of a multilink as template items (for the API and the preview).
     *
     * @param list<array<string, mixed>> $children
     * @return list<array<string, mixed>>
     */
    public function children(array $children, string $iconStyle): array
    {
        $items = [];

        foreach ($children as $child) {
            $item = $this->item($child, $iconStyle);

            if ($item !== null && $item['type'] === 'link') {
                $items[] = $item['vars'];
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $block
     * @return array{type: string, tile: bool, template: string, vars: array<string, mixed>}|null
     */
    private function item(array $block, string $iconStyle): ?array
    {
        $module = $this->modules->get((string) ($block['type'] ?? ''));

        if ($module === null || empty($block['is_active'])) {
            return null;
        }

        $result = $this->validator->validate($module, is_array($block['data'] ?? null) ? $block['data'] : []);

        if ($result['errors'] !== []) {
            return null;
        }

        $context = new RenderContext($this->icons, $iconStyle, (int) ($block['id'] ?? 0), $this->mediaRow, $this->timezone);
        $vars = $module->view($result['data'], $block['children'] ?? [], $context);
        $vars['id'] = $block['key'] ?? $block['id'] ?? '';

        return [
            'type' => $module->type(),
            'tile' => $module->isTile(),
            'template' => '@module_' . $module->type() . '/template.twig',
            'vars' => $vars,
        ];
    }

    /**
     * Complete quicklinks with their icon, in the same icon style as the tiles.
     *
     * @return list<array{label: string, url: string, icon: array{html: string, color: string, style: string}}>
     */
    private function quicklinks(mixed $quicklinks, string $iconStyle): array
    {
        $context = new RenderContext($this->icons, $iconStyle, 0, $this->mediaRow);

        return array_map(
            static fn(array $link): array => ['label' => $link['label'], 'url' => $link['url'], 'icon' => $context->icon($link['icon'])],
            Quicklinks::clean($quicklinks, $this->icons, true),
        );
    }

    public static function initials(string $name): string
    {
        $words = preg_split('/[\s_.-]+/u', trim($name)) ?: [];
        $letters = array_map(static fn(string $w): string => mb_strtoupper(mb_substr($w, 0, 1)), array_filter($words));

        return implode('', array_slice($letters, 0, 2)) ?: 'B';
    }
}
