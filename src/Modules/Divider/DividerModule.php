<?php

declare(strict_types=1);

namespace Bioly\Modules\Divider;

use Bioly\Module\AbstractModule;
use Bioly\Module\RenderContext;

final class DividerModule extends AbstractModule
{
    public function type(): string
    {
        return 'divider';
    }

    public function fields(): array
    {
        return [
            ['name' => 'title', 'type' => 'text', 'max' => 60],
        ];
    }

    public function view(array $data, array $children, RenderContext $context): array
    {
        return ['title' => $data['title'] ?? ''];
    }

    public function sortOrder(): int
    {
        return 90;
    }
}
