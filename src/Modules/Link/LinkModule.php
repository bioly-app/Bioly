<?php

declare(strict_types=1);

namespace Bioly\Modules\Link;

use Bioly\Module\AbstractModule;
use Bioly\Module\RenderContext;
use Bioly\Module\Validator;
use Bioly\Theme\Theme;

final class LinkModule extends AbstractModule
{
    public function type(): string
    {
        return 'link';
    }

    public function fields(): array
    {
        return [
            ['name' => 'title', 'type' => 'text', 'required' => true, 'max' => 80, 'group' => 'text'],
            ['name' => 'subtitle', 'type' => 'text', 'max' => 120, 'group' => 'text'],
            ['name' => 'url', 'type' => 'url', 'required' => true, 'max' => Validator::MAX_URL, 'group' => 'target'],
            ['name' => 'new_tab', 'type' => 'toggle', 'default' => true, 'group' => 'target'],
            ['name' => 'icon', 'type' => 'icon', 'group' => 'icon'],
            ['name' => 'icon_style', 'type' => 'select', 'options' => Theme::ICON_STYLES, 'group' => 'icon'],
        ];
    }

    public function isTile(): bool
    {
        return true;
    }

    public function view(array $data, array $children, RenderContext $context): array
    {
        return [
            'title' => $data['title'],
            'subtitle' => $data['subtitle'] ?? '',
            'url' => $data['url'],
            'new_tab' => !empty($data['new_tab']),
            'icon' => $context->icon(($data['icon'] ?? '') ?: null, $data['icon_style'] ?? null),
        ];
    }
}
