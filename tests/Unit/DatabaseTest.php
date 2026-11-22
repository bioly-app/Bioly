<?php

declare(strict_types=1);

namespace Bioly\Tests\Unit;

use Bioly\Database\Connection;
use Bioly\Database\Migrator;
use Bioly\Repository\BlockRepository;
use Bioly\Repository\SettingsRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
    }

    public function testMigrationsRunOnce(): void
    {
        $migrator = new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations');

        self::assertFalse($migrator->hasPending());
        self::assertSame([], $migrator->migrate());
    }

    public function testSettingsRoundTrip(): void
    {
        $settings = new SettingsRepository($this->pdo);
        $settings->set('site', ['name' => 'Ä', 'bio' => 'x']);
        $settings->merge('site', ['bio' => 'y']);

        self::assertSame(['name' => 'Ä', 'bio' => 'y'], $settings->getArray('site'));
    }

    public function testBlocksKeepOrderAndChildren(): void
    {
        $blocks = new BlockRepository($this->pdo);
        $blocks->replaceAll([
            ['type' => 'divider', 'is_active' => true, 'data' => ['title' => 'A']],
            ['type' => 'multilink', 'is_active' => true, 'data' => ['title' => 'M'], 'children' => [
                ['type' => 'link', 'is_active' => true, 'data' => ['title' => 'L1']],
                ['type' => 'link', 'is_active' => false, 'data' => ['title' => 'L2']],
            ]],
        ]);

        $tree = $blocks->tree();
        self::assertSame(['A', 'M'], array_map(static fn(array $b): string => $b['data']['title'], $tree));
        self::assertCount(2, $tree[1]['children']);
        self::assertCount(1, $blocks->activeChildren($tree[1]['id']));
        self::assertCount(1, $blocks->tree(true)[1]['children']);
    }
}
