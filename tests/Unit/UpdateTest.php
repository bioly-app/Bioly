<?php

declare(strict_types=1);

namespace Bioly\Tests\Unit;

use Bioly\Database\Connection;
use Bioly\Database\Migrator;
use Bioly\Update\Backups;
use Bioly\Update\Files;
use Bioly\Update\Package;
use Bioly\Update\UpdateException;
use Bioly\Update\Updater;
use PDO;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class UpdateTest extends TestCase
{
    private string $folder;
    private string $root;
    private string $publicKey;
    private string $secretKey;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/bioly-update-' . bin2hex(random_bytes(4));
        $this->root = $this->folder . '/site';
        $pair = sodium_crypto_sign_keypair();
        $this->publicKey = base64_encode(sodium_crypto_sign_publickey($pair));
        $this->secretKey = sodium_crypto_sign_secretkey($pair);

        // An installed Bioly 1.0.0: program files, its manifest and a database in data/.
        $this->writeSite(['VERSION' => "1.0.0\n", 'index.php' => 'old', 'src/Old.php' => 'old only', 'src/Keep.php' => 'keep v1']);
        foreach (['backups', 'updates', 'cache', 'uploads'] as $folder) {
            mkdir($this->root . '/data/' . $folder, 0775, true);
        }

        $pdo = $this->pdo();
        (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $pdo->exec("INSERT INTO users (username, password_hash, created_at, updated_at) VALUES ('owner', 'hash', 'now', 'now')");
        $pdo->exec("INSERT INTO blocks (type, data, created_at, updated_at) VALUES ('link', '{\"title\":\"before\"}', 'now', 'now')");
        $pdo->exec("INSERT INTO settings (key, value, updated_at) VALUES ('site', '{\"name\":\"Mine\",\"url\":\"https://mine.example\"}', 'now'), ('smtp', '{\"host\":\"mine\"}', 'now')");
    }

    protected function tearDown(): void
    {
        Files::removeTree($this->folder);
    }

    public function testSignedPackageIsCheckedAndInstalledWithBackup(): void
    {
        $zip = $this->zip('2.0.0', ['VERSION' => "2.0.0\n", 'index.php' => 'new', 'src/Keep.php' => 'keep v2', 'src/New.php' => 'new only']);
        $package = Package::open($zip, $this->folder . '/work', $this->publicKey);
        self::assertTrue($package->signed);
        self::assertSame('2.0.0', $package->version);

        $updater = $this->updater();
        $updater->install($package);

        self::assertSame("2.0.0\n", file_get_contents($this->root . '/VERSION'));
        self::assertSame('keep v2', file_get_contents($this->root . '/src/Keep.php'));
        self::assertFileExists($this->root . '/src/New.php');
        // Listed in the old manifest, gone from the new one: removed.
        self::assertFileDoesNotExist($this->root . '/src/Old.php');
        // data/ is never touched.
        self::assertFileExists($this->root . '/data/app.sqlite');
        self::assertSame('2.0.0', $updater->pending()['to'] ?? null);

        // AfterUpdate fails: the backup goes back in.
        $this->pdo()->exec("UPDATE blocks SET data = '{\"title\":\"after\"}'");
        $updater->finishFailed(new \RuntimeException('migration broke'));
        self::assertSame("1.0.0\n", file_get_contents($this->root . '/VERSION'));
        self::assertSame('old only', file_get_contents($this->root . '/src/Old.php'));
        self::assertFileDoesNotExist($this->root . '/src/New.php');
        self::assertSame(['before'], $this->titles());
        self::assertNull($updater->pending());
        self::assertSame('2.0.0', $updater->takeFailure()['to'] ?? null);
        self::assertNull($updater->takeFailure());
    }

    public function testPackagesThatAreNotGenuineAreRefused(): void
    {
        $files = ['VERSION' => "2.0.0\n", 'index.php' => 'new'];

        // No signature
        $this->assertRefused('update.error.signature', $this->zip('2.0.0', $files, sign: false));
        // ...unless the test site allows it
        self::assertFalse(Package::open($this->zip('2.0.0', $files, sign: false), $this->folder . '/w1', $this->publicKey, true)->signed);
        // Signed with another key
        $this->assertRefused('update.error.signature', $this->zip('2.0.0', $files, key: sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
        // A file changed after signing
        $this->assertRefused('update.error.changed', $this->zip('2.0.0', $files, tamper: ['index.php' => 'evil']));
        // An extra file that is not in the manifest
        $this->assertRefused('update.error.changed', $this->zip('2.0.0', $files, tamper: ['src/Extra.php' => 'evil']));
        // A path outside bioly/
        $this->assertRefused('update.error.not_bioly', $this->zip('2.0.0', $files, raw: ['../evil.php' => 'x']));

        // Not newer than the installed version
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('update.error.not_newer');
        $this->updater()->install(Package::open($this->zip('1.0.0', ['VERSION' => "1.0.0\n"]), $this->folder . '/w2', $this->publicKey));
    }

    public function testUpdateBackupsHoldProgramAndDatabaseAndOnlyTheLastTwoStay(): void
    {
        $backups = $this->backups();
        $first = $backups->create();
        self::assertFileExists($this->root . '/data/backups/' . $first . '/files/index.php');
        file_put_contents($this->root . '/index.php', 'changed');
        $this->pdo()->exec("UPDATE blocks SET data = '{\"title\":\"later\"}'");
        $this->pdo()->exec("UPDATE users SET username = 'renamed'");

        $backups->restore($first);
        self::assertSame('old', file_get_contents($this->root . '/index.php'));
        self::assertSame(['before'], $this->titles());
        // Everything as before the update, the account too.
        self::assertSame('owner', $this->pdo()->query('SELECT username FROM users')->fetchColumn());

        foreach ([1, 2] as $_) {
            sleep(1);
            $backups->create();
        }

        self::assertCount(2, $backups->all());
        self::assertFalse($backups->exists($first));
        self::assertFalse($backups->exists('../etc'));
    }

    public function testOwnBackupsMoveContentToAnotherInstallation(): void
    {
        file_put_contents($this->root . '/data/uploads/image-0123456789abcdef.webp', 'photo');
        $backups = $this->backups();
        $id = $backups->create(true);
        self::assertDirectoryDoesNotExist($this->root . '/data/backups/' . $id . '/files');

        $zip = $this->folder . '/download.zip';
        $backups->zip($id, $zip);

        // Another installation: own account, own mail settings, own address, other content.
        $this->pdo()->exec("UPDATE blocks SET data = '{\"title\":\"other\"}'");
        $this->pdo()->exec("UPDATE users SET username = 'someone-else'");
        $this->pdo()->exec("UPDATE settings SET value = '{\"name\":\"Other\",\"url\":\"https://other.example\"}' WHERE key = 'site'");
        $this->pdo()->exec("UPDATE settings SET value = '{\"host\":\"other\"}' WHERE key = 'smtp'");
        unlink($this->root . '/data/uploads/image-0123456789abcdef.webp');
        file_put_contents($this->root . '/data/uploads/image-fedcba9876543210.webp', 'other photo');
        $backups->delete($id);

        $imported = $backups->import($zip);
        self::assertSame('manual', $backups->find($imported)['kind'] ?? null);
        $backups->restore($imported);

        self::assertSame(['before'], $this->titles());
        self::assertFileExists($this->root . '/data/uploads/image-0123456789abcdef.webp');
        self::assertFileDoesNotExist($this->root . '/data/uploads/image-fedcba9876543210.webp');
        // What belongs to this installation stays.
        self::assertSame('someone-else', $this->pdo()->query('SELECT username FROM users')->fetchColumn());
        $settings = $this->pdo()->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame(['name' => 'Mine', 'url' => 'https://other.example'], json_decode($settings['site'], true));
        self::assertSame(['host' => 'other'], json_decode($settings['smtp'], true));

        // A backup of a newer Bioly does not go into an older one.
        $this->expectExceptionMessage('update.error.backup_newer');
        $this->backups('0.9.0')->restore($imported);
    }

    public function testUploadedBackupsMustBeContentOnly(): void
    {
        $backups = $this->backups();
        $bad = function (array $entries): string {
            $path = $this->folder . '/bad-' . bin2hex(random_bytes(3)) . '.zip';
            $zip = new ZipArchive();
            $zip->open($path, ZipArchive::CREATE);

            foreach ($entries as $name => $content) {
                $zip->addFromString($name, $content);
            }

            $zip->close();

            return $path;
        };
        $info = '{"version":"1.0.0","kind":"manual"}';
        $database = (string) file_get_contents($this->root . '/data/app.sqlite');

        foreach ([
            ['bioly-backup/backup.json' => $info, 'bioly-backup/app.sqlite' => $database, 'bioly-backup/files/index.php' => '<?php evil();'],
            ['bioly-backup/backup.json' => $info, 'bioly-backup/app.sqlite' => $database, 'bioly-backup/uploads/evil.php' => 'x'],
            ['bioly-backup/backup.json' => $info, 'bioly-backup/app.sqlite' => 'not a database'],
            ['other/backup.json' => $info],
        ] as $entries) {
            try {
                $backups->import($bad($entries));
                self::fail('Accepted a bad backup');
            } catch (UpdateException $exception) {
                self::assertSame('update.error.not_backup', $exception->getMessage());
            }
        }

        self::assertSame([], $backups->all());

        // The two kinds of zip in the wrong field get a hint where they belong.
        $update = $this->zip('2.0.0', ['VERSION' => "2.0.0\n"]);
        $this->assertSame('update.error.is_update', $this->errorOf(fn() => $backups->import($update)));
        $backup = $bad(['bioly-backup/backup.json' => $info, 'bioly-backup/app.sqlite' => $database]);
        $this->assertSame('update.error.is_backup', $this->errorOf(fn() => Package::open($backup, $this->folder . '/w3', $this->publicKey)));
    }

    private function errorOf(callable $action): string
    {
        try {
            $action();
        } catch (UpdateException $exception) {
            return $exception->getMessage();
        }

        return '';
    }

    public function testDevelopmentCopiesAreNeverUpdated(): void
    {
        $updater = new Updater($this->root, $this->root . '/data/updates', '1.0.0', $this->backups(), true);
        self::assertContains('update.blocked.development', $updater->blockers());

        mkdir($this->root . '/.git');
        $updater = $this->updater();
        self::assertContains('update.blocked.development', $updater->blockers());
    }

    private function assertRefused(string $error, string $zip): void
    {
        try {
            Package::open($zip, $this->folder . '/work-' . bin2hex(random_bytes(3)), $this->publicKey);
            self::fail('Expected ' . $error);
        } catch (UpdateException $exception) {
            self::assertSame($error, $exception->getMessage());
        }
    }

    /**
     * @param array<string, string> $files
     * @param array<string, string> $tamper files changed or added after signing
     * @param array<string, string> $raw entries written as they are (outside bioly/)
     */
    private function zip(string $version, array $files, bool $sign = true, ?string $key = null, array $tamper = [], array $raw = []): string
    {
        $hashes = array_map(static fn(string $content): string => hash('sha256', $content), $files);
        ksort($hashes);
        $manifest = json_encode(['name' => 'bioly', 'version' => $version, 'php' => '8.3.0', 'files' => $hashes]) . "\n";
        $path = $this->folder . '/zip-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);

        foreach ([...$files, ...$tamper] as $name => $content) {
            $zip->addFromString('bioly/' . $name, $content);
        }

        foreach ($raw as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->addFromString('bioly/manifest.json', $manifest);

        if ($sign) {
            $zip->addFromString('bioly/manifest.sig', base64_encode(sodium_crypto_sign_detached($manifest, $key ?? $this->secretKey)));
        }

        $zip->close();

        return $path;
    }

    /** @param array<string, string> $files */
    private function writeSite(array $files): void
    {
        foreach ($files as $name => $content) {
            @mkdir(dirname($this->root . '/' . $name), 0775, true);
            file_put_contents($this->root . '/' . $name, $content);
        }

        $hashes = array_map(static fn(string $content): string => hash('sha256', $content), $files);
        file_put_contents($this->root . '/manifest.json', json_encode(['name' => 'bioly', 'version' => '1.0.0', 'files' => $hashes]));
    }

    private ?PDO $connection = null;

    private function pdo(): PDO
    {
        return $this->connection ??= Connection::create($this->root . '/data/app.sqlite');
    }

    /** @return list<string> */
    private function titles(): array
    {
        return array_map(static fn(string $data): string => (string) json_decode($data, true)['title'], $this->pdo()->query('SELECT data FROM blocks ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    private function backups(string $version = '1.0.0'): Backups
    {
        return new Backups($this->root . '/data/backups', $this->root, $this->pdo(), $this->root . '/data/uploads', dirname(__DIR__, 2) . '/migrations', $version);
    }

    private function updater(): Updater
    {
        return new Updater($this->root, $this->root . '/data/updates', '1.0.0', $this->backups(), false);
    }
}
