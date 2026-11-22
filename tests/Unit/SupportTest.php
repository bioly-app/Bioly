<?php

declare(strict_types=1);

namespace Bioly\Tests\Unit;

use Bioly\Page\PageBuilder;
use Bioly\Service\InAppBrowser;
use Bioly\Service\LegalText;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    public function testLegalTextEscapesHtml(): void
    {
        $html = (new LegalText())->toHtml("## Kontakt\n\n<script>x</script> **fett**\n\n- eins\n- [Link](https://a.de)\n\n---");

        self::assertStringContainsString('<h2>Kontakt</h2>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('<strong>fett</strong>', $html);
        self::assertStringContainsString('<li><a href="https://a.de" rel="noopener">Link</a></li>', $html);
        self::assertStringContainsString('<hr>', $html);
    }

    public function testLegalTextIgnoresJavascriptLinks(): void
    {
        self::assertStringNotContainsString('href', (new LegalText())->toHtml('[x](javascript:alert(1))'));
    }

    public function testDetectsInAppBrowsers(): void
    {
        self::assertTrue(InAppBrowser::detect('Mozilla/5.0 (iPhone) Instagram 300.0'));
        self::assertTrue(InAppBrowser::detect('Mozilla/5.0 (Linux; Android) TikTok/30'));
        self::assertFalse(InAppBrowser::detect('Mozilla/5.0 (Macintosh) Safari/605.1.15'));
    }

    public function testInitials(): void
    {
        self::assertSame('PE', PageBuilder::initials('Pup_Enzo'));
        self::assertSame('B', PageBuilder::initials(''));
    }
}
