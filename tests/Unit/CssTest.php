<?php

declare(strict_types=1);

namespace Bioly\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Keeps the styles maintainable: colors are defined once as variables, never repeated in rules.
 */
final class CssTest extends TestCase
{
    private const COLOR = '/#[0-9a-f]{3,8}\b|\brgba?\(|\bhsla?\(/i';

    public function testBackendColorsLiveOnlyInTokens(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/public/assets/css/backend/*.css') ?: [];
        self::assertContains('tokens.css', array_map('basename', $files));

        foreach ($files as $file) {
            if (basename($file) === 'tokens.css') {
                continue;
            }

            foreach (self::declarations($file) as $line) {
                self::assertDoesNotMatchRegularExpression(self::COLOR, $line, basename($file) . ': use a variable from tokens.css');
            }
        }
    }

    public function testSiteColorsLiveOnlyInRoot(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/site.css');
        // Everything after the first :root block, without comments and data URIs.
        $rules = substr($css, (int) strpos($css, "\n}\n", (int) strpos($css, ':root {')));
        $rules = (string) preg_replace(['~/\*.*?\*/~s', '~url\("data:[^"]*"\)~'], '', $rules);

        self::assertDoesNotMatchRegularExpression(self::COLOR, $rules, 'site.css: define the color in :root');
    }

    /** @return list<string> declarations of a file, without comments, custom properties and data URIs */
    private static function declarations(string $file): array
    {
        $css = (string) preg_replace(['~/\*.*?\*/~s', '~url\("data:[^"]*"\)~'], '', (string) file_get_contents($file));

        return array_values(array_filter(
            array_map('trim', explode(';', $css)),
            static fn(string $line): bool => $line !== '' && !str_starts_with($line, '--'),
        ));
    }
}
