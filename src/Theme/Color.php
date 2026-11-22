<?php

declare(strict_types=1);

namespace Bioly\Theme;

/**
 * Small color helpers following the WCAG 2.x formulas.
 */
final class Color
{
    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }

    /** @return array{int, int, int} */
    public static function rgb(string $hex): array
    {
        $n = hexdec(ltrim($hex, '#'));

        return [($n >> 16) & 255, ($n >> 8) & 255, $n & 255];
    }

    /** @param array{int|float, int|float, int|float} $rgb */
    public static function hex(array $rgb): string
    {
        return sprintf('#%02X%02X%02X', ...array_map(static fn($v): int => (int) round(max(0, min(255, $v))), $rgb));
    }

    public static function luminance(string $hex): float
    {
        $channels = array_map(static function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Mixes $color with $with; $amount 0 = $color, 1 = $with. */
    public static function mix(string $color, string $with, float $amount): string
    {
        $a = self::rgb($color);
        $b = self::rgb($with);

        return self::hex([
            $a[0] + ($b[0] - $a[0]) * $amount,
            $a[1] + ($b[1] - $a[1]) * $amount,
            $a[2] + ($b[2] - $a[2]) * $amount,
        ]);
    }

    public static function isDark(string $background): bool
    {
        return self::contrast($background, '#FFFFFF') >= self::contrast($background, '#000000');
    }

    /** White or near black, whichever reads better on $background. */
    public static function readableOn(string $background): string
    {
        return self::isDark($background) ? '#FFFFFF' : '#18181B';
    }

    /**
     * Lightens (on dark backgrounds) or darkens (on light ones) $color
     * in small steps until it reaches $minimum contrast.
     */
    public static function ensureContrast(string $color, string $background, float $minimum): string
    {
        $target = self::isDark($background) ? '#FFFFFF' : '#000000';
        $result = $color;

        for ($step = 1; $step <= 20 && self::contrast($result, $background) < $minimum; $step++) {
            $result = self::mix($color, $target, $step * 0.05);
        }

        return $result;
    }
}
