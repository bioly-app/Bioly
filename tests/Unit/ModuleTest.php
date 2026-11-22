<?php

declare(strict_types=1);

namespace Bioly\Tests\Unit;

use Bioly\Icon\IconRegistry;
use Bioly\Module\ModuleRegistry;
use Bioly\Module\RenderContext;
use Bioly\Module\Validator;
use Bioly\Modules\Multilink\MultilinkModule;
use PHPUnit\Framework\TestCase;

final class ModuleTest extends TestCase
{
    private ModuleRegistry $modules;
    private Validator $validator;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->modules = new ModuleRegistry($root . '/src/Modules');
        $icons = new IconRegistry($root . '/resources/icons', $root . '/resources/originals', sys_get_temp_dir() . '/bioly-test-icons-' . bin2hex(random_bytes(3)) . '.php');
        $this->validator = new Validator($icons);
    }

    public function testFindsModulesByFolder(): void
    {
        self::assertSame(['countdown', 'divider', 'image', 'link', 'multilink'], $this->sortedTypes());
        self::assertTrue($this->modules->get('multilink')?->acceptsChildren());
        self::assertArrayHasKey('module_link', $this->modules->templateNamespaces());
    }

    public function testLinkNeedsTitleAndValidUrl(): void
    {
        $result = $this->validator->validate($this->modules->get('link'), ['title' => '', 'url' => 'javascript:alert(1)']);

        self::assertSame('validation.required', $result['errors']['title']);
        self::assertSame('validation.url', $result['errors']['url']);
    }

    public function testLinkAcceptsMailAndTel(): void
    {
        foreach (['mailto:a@b.de', 'tel:+4912345', 'https://example.com/x?y=1'] as $url) {
            $result = $this->validator->validate($this->modules->get('link'), ['title' => 'X', 'url' => $url]);
            self::assertSame([], $result['errors'], $url);
        }
    }

    public function testUnknownIconAndKeysAreRejected(): void
    {
        $result = $this->validator->validate($this->modules->get('link'), ['title' => 'X', 'url' => 'https://a.de', 'icon' => 'std:nope', 'evil' => 1]);

        self::assertSame('validation.icon', $result['errors']['icon']);
        self::assertArrayNotHasKey('evil', $result['data']);
    }

    public function testMultilinkAgeGateNeedsTheConfirmation(): void
    {
        $result = $this->validator->validate($this->modules->get('multilink'), ['title' => 'X', 'age_gate' => '1']);

        self::assertTrue($result['data']['age_gate']);
        self::assertFalse(MultilinkModule::passesAgeGate($result['data'], ''));
        self::assertFalse(MultilinkModule::passesAgeGate($result['data'], '16'));
        self::assertTrue(MultilinkModule::passesAgeGate($result['data'], '18'));
        self::assertTrue(MultilinkModule::passesAgeGate(['age_gate' => false], ''));
    }

    public function testCountdownChecksDateAndTime(): void
    {
        $countdown = $this->modules->get('countdown');
        $result = $this->validator->validate($countdown, ['title' => 'Konzert', 'start_date' => '2026-02-30', 'start_time' => '25:00', 'end_date' => '', 'end_time' => '7:5']);

        self::assertSame('validation.date', $result['errors']['start_date']);
        self::assertSame('validation.time', $result['errors']['start_time']);
        self::assertSame('validation.time', $result['errors']['end_time']);
        self::assertSame([], $this->validator->validate($countdown, ['title' => 'Konzert', 'start_date' => '2026-12-12', 'start_time' => '19:30'])['errors']);
    }

    public function testCountdownStatesFollowStartAndEnd(): void
    {
        $countdown = $this->modules->get('countdown');
        $root = dirname(__DIR__, 2);
        $icons = new IconRegistry($root . '/resources/icons', $root . '/resources/originals', sys_get_temp_dir() . '/bioly-test-icons-' . bin2hex(random_bytes(3)) . '.php');
        $data = $this->validator->validate($countdown, [
            'title' => 'Konzert', 'start_date' => '2026-12-12', 'start_time' => '19:30', 'end_time' => '23:00', 'after' => 'over', 'display' => 'large',
        ])['data'];
        $at = fn(string $time): array => $countdown->view($data, [], new RenderContext($icons, 'tinted', 1, null, 'Europe/Berlin', new \DateTimeImmutable($time)));

        $before = $at('2026-11-22T03:50:00+01:00');
        self::assertSame('upcoming', $before['state']);
        self::assertSame(['days' => 20, 'hours' => 15, 'minutes' => 40], $before['left']);
        self::assertSame('2026-12-12T19:30:00+01:00', $before['start_iso']);
        self::assertSame('2026-12-12T23:00:00+01:00', $before['end_iso']);
        self::assertSame(['kind' => 'time', 'time' => '23:00'], $before['until']);
        self::assertSame(['weekday' => 6, 'day' => 12, 'month' => 12], $before['start']);

        self::assertSame('live', $at('2026-12-12T20:00:00+01:00')['state']);
        self::assertSame('over', $at('2026-12-12T23:00:00+01:00')['state']);

        // Hidden after the end by default; without an end the end of the start day counts.
        $hide = $this->validator->validate($countdown, ['title' => 'Fest', 'start_date' => '2026-12-12'])['data'];
        $context = fn(string $time) => new RenderContext($icons, 'tinted', 1, null, 'Europe/Berlin', new \DateTimeImmutable($time));
        self::assertSame('live', $countdown->view($hide, [], $context('2026-12-12T23:59:00+01:00'))['state']);
        self::assertSame(['hidden' => true], $countdown->view($hide, [], $context('2026-12-13T00:00:00+01:00')));

        // Several days: "until" names the last day; an end before the start is ignored.
        $long = $this->validator->validate($countdown, ['title' => 'Festival', 'start_date' => '2026-12-11', 'end_date' => '2026-12-13'])['data'];
        $view = $countdown->view($long, [], $context('2026-12-01T12:00:00+01:00'));
        self::assertSame(['kind' => 'date', 'date' => ['weekday' => 0, 'day' => 13, 'month' => 12]], $view['until']);
        $wrong = $this->validator->validate($countdown, ['title' => 'X', 'start_date' => '2026-12-11', 'end_date' => '2026-12-01'])['data'];
        self::assertSame('2026-12-12T00:00:00+01:00', $countdown->view($wrong, [], $context('2026-12-01T12:00:00+01:00'))['end_iso']);
    }

    public function testImageModuleChecksTheImageList(): void
    {
        $module = $this->modules->get('image');
        self::assertNotNull($module);
        $file = 'image-0123456789abcdef.webp';

        foreach (['', '[]', '{"a":1}', '[{"file":"../etc/passwd"}]', '[{"file":"' . $file . '","url":"javascript:alert(1)"}]', json_encode(array_fill(0, 13, ['file' => $file]))] as $bad) {
            self::assertSame(
                $bad === '' ? 'validation.required' : 'validation.images',
                $this->validator->validate($module, ['images' => (string) $bad])['errors']['images'] ?? null,
                (string) $bad,
            );
        }

        $result = $this->validator->validate($module, ['images' => '[{"file":"' . $file . '","url":" https://a.de/x ","evil":1}]', 'ratio' => '16-9']);
        self::assertSame([], $result['errors']);
        self::assertSame('[{"file":"' . $file . '","url":"https://a.de/x"}]', $result['data']['images']);

        // Rendering takes alt text and color from the library and drops missing files.
        $rows = [$file => ['filename' => $file, 'alt_text' => 'Konzert', 'color' => '#336699', 'width' => 800, 'height' => 600]];
        $context = new RenderContext(
            new IconRegistry(dirname(__DIR__, 2) . '/resources/icons', dirname(__DIR__, 2) . '/resources/originals', sys_get_temp_dir() . '/bioly-test-icons-x.php'),
            'tinted',
            1,
            fn(string $name): ?array => $rows[$name] ?? null,
        );
        $view = $module->view(['images' => '[{"file":"' . $file . '","url":""},{"file":"image-ffffffffffffffff.webp","url":""}]', 'ratio' => '16-9', 'autoplay' => true, 'title' => ''], [], $context);
        self::assertCount(1, $view['images']);
        self::assertSame('Konzert', $view['images'][0]['alt']);
        self::assertSame('#336699', $view['images'][0]['color']);
        self::assertFalse($view['autoplay'], 'autoplay needs at least two images');
    }

    /** @return list<string> */
    private function sortedTypes(): array
    {
        $types = array_keys($this->modules->all());
        sort($types);

        return $types;
    }
}
