<?php

declare(strict_types=1);

namespace Bioly\Tests\Unit;

use Bioly\I18n\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/bioly-lang-' . bin2hex(random_bytes(4));
        mkdir($this->path . '/module', 0777, true);
        file_put_contents($this->path . '/de.json', '{"greeting": "Hallo {name}"}');
        file_put_contents($this->path . '/en.json', '{"greeting": "Hello {name}", "only_en": "English"}');
        file_put_contents($this->path . '/module/de.json', '{"module.x": "Modul"}');
    }

    protected function tearDown(): void
    {
        array_map('unlink', [...glob($this->path . '/*.json'), ...glob($this->path . '/module/*.json')]);
        rmdir($this->path . '/module');
        rmdir($this->path);
    }

    public function testTranslatesWithParameters(): void
    {
        self::assertSame('Hallo Alex', $this->translator()->translate('greeting', ['name' => 'Alex']));
    }

    public function testFallsBackToEnglishThenKey(): void
    {
        self::assertSame('English', $this->translator()->translate('only_en'));
        self::assertSame('missing.key', $this->translator()->translate('missing.key'));
    }

    public function testMergesModuleFolders(): void
    {
        self::assertSame('Modul', $this->translator()->translate('module.x'));
    }

    public function testFiltersByPrefix(): void
    {
        self::assertSame(['module.x' => 'Modul'], $this->translator()->all(['module.']));
    }

    private function translator(): Translator
    {
        return new Translator([$this->path, $this->path . '/module'], 'de', 'en');
    }
}
