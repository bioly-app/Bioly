<?php

declare(strict_types=1);

namespace Bioly\Page;

use Bioly\Icon\IconRegistry;
use Bioly\Module\Validator;

/**
 * Quicklinks in the header: the only place that cleans and checks them.
 * The draft keeps unfinished rows; the page shows complete ones only.
 */
final class Quicklinks
{
    public const MAX = 5;
    public const LABEL_MAX = 60;

    /**
     * @param bool $complete only rows with an existing icon, a description and a valid address
     * @return list<array{icon: string, url: string, label: string}>
     */
    public static function clean(mixed $input, IconRegistry $icons, bool $complete): array
    {
        $links = [];

        foreach (is_array($input) ? array_slice($input, 0, self::MAX) : [] as $link) {
            if (!is_array($link)) {
                continue;
            }

            $clean = [
                'icon' => is_string($link['icon'] ?? null) && $icons->has($link['icon']) ? $link['icon'] : '',
                'url' => is_string($link['url'] ?? null) ? mb_substr(trim($link['url']), 0, Validator::MAX_URL) : '',
                'label' => is_string($link['label'] ?? null) ? mb_substr(trim($link['label']), 0, self::LABEL_MAX) : '',
            ];

            if (!$complete || ($clean['icon'] !== '' && $clean['label'] !== '' && Validator::isUrl($clean['url']))) {
                $links[] = $clean;
            }
        }

        return $links;
    }
}
