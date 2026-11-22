<?php

declare(strict_types=1);

namespace Bioly\Tests\Unit;

use Bioly\Support\SiteUrl;
use PHPUnit\Framework\TestCase;

final class SiteUrlTest extends TestCase
{
    public function testKeepsOnlySchemeHostAndPort(): void
    {
        self::assertSame('https://example.org', SiteUrl::normalize(' https://Example.org/ '));
        self::assertSame('http://localhost:8080', SiteUrl::normalize('http://localhost:8080'));
    }

    public function testRejectsEverythingElse(): void
    {
        foreach (['example.org', 'ftp://example.org', 'https://example.org/admin', 'https://example.org?x=1',
            'https://user@example.org', 'javascript:alert(1)', 'https://exa mple.org', 'https://evil.org#x', ''] as $value) {
            self::assertNull(SiteUrl::normalize($value), $value);
        }
    }
}
