<?php

declare(strict_types=1);

namespace Bioly\Modules\Multilink;

use Bioly\Module\AbstractModule;
use Bioly\Module\RenderContext;

/**
 * A button that opens a dialog with more links. The links are never part of
 * the page HTML: the browser loads them from /api/multilink/{id}, and only
 * outside in-app browsers.
 */
final class MultilinkModule extends AbstractModule
{
    /** Minimum age for multilinks with the age check. */
    public const MIN_AGE = 18;

    /** Request header the page sends after the visitor confirmed the age; the birth date itself never leaves the browser. */
    public const AGE_HEADER = 'X-Bioly-Age-Confirmed';

    /**
     * Whether the links of a multilink may be sent. The age check is a self-declaration of the
     * visitor; the server only hands out the links after the page asked for it.
     *
     * @param array<string, mixed> $data
     */
    public static function passesAgeGate(array $data, string $header): bool
    {
        return empty($data['age_gate']) || $header === (string) self::MIN_AGE;
    }

    public function type(): string
    {
        return 'multilink';
    }

    public function fields(): array
    {
        return [
            ['name' => 'title', 'type' => 'text', 'required' => true, 'max' => 80, 'group' => 'text'],
            ['name' => 'subtitle', 'type' => 'text', 'max' => 120, 'group' => 'text'],
            ['name' => 'icon', 'type' => 'icon', 'group' => 'icon'],
            ['name' => 'age_gate', 'type' => 'toggle', 'default' => false, 'group' => 'protection'],
        ];
    }

    public function isTile(): bool
    {
        return true;
    }

    public function acceptsChildren(): bool
    {
        return true;
    }

    public function view(array $data, array $children, RenderContext $context): array
    {
        return [
            'id' => $context->blockId,
            'title' => $data['title'],
            'subtitle' => $data['subtitle'] ?? '',
            'icon' => $context->icon(($data['icon'] ?? '') ?: null),
            'age_gate' => !empty($data['age_gate']) ? self::MIN_AGE : 0,
        ];
    }
}
