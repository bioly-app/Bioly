<?php

declare(strict_types=1);

namespace Bioly\Support;

/**
 * All stored times are UTC strings: YYYY-MM-DD HH:MM:SS.
 */
final class Clock
{
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function in(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() + $seconds);
    }

    public static function ago(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() - $seconds);
    }
}
