<?php

declare(strict_types=1);

namespace Bioly;

use Bioly\Database\Connection;
use Bioly\Database\Migrator;
use Bioly\I18n\Translator;
use Bioly\Icon\IconRegistry;
use Bioly\Icon\UiIcons;
use Bioly\Module\ModuleRegistry;
use Bioly\Module\Validator;
use Bioly\Page\Draft;
use Bioly\Page\PageBuilder;
use Bioly\Page\Publisher;
use Bioly\Repository\BlockRepository;
use Bioly\Repository\LoginAttemptRepository;
use Bioly\Repository\MediaRepository;
use Bioly\Repository\PageRepository;
use Bioly\Repository\RateHitRepository;
use Bioly\Repository\SettingsRepository;
use Bioly\Repository\TokenRepository;
use Bioly\Repository\UserRepository;
use Bioly\Service\AccountService;
use Bioly\Service\Auth;
use Bioly\Service\FaviconService;
use Bioly\Service\LegalText;
use Bioly\Service\MailService;
use Bioly\Service\MediaService;
use Bioly\Service\RateLimiter;
use Bioly\Support\AppConfig;
use Bioly\Support\Crypto;
use Bioly\Support\Session;
use Bioly\Support\SiteUrl;
use Bioly\Theme\Theme;
use Bioly\Twig\AppExtension;
use Bioly\Update\AfterUpdate;
use Bioly\Update\Backups;
use Bioly\Update\HttpClient;
use Bioly\Update\ReleaseFeed;
use Bioly\Update\UpdateCheck;
use Bioly\Update\Updater;
use LogicException;
use PDO;
use Slim\Views\Twig;

/**
 * Creates every service once, on first use. No magic, no reflection.
 */
final class Services
{
    /** @var array<string, object> */
    private array $instances = [];

    /** @var list<array{id: string, size: int, version: string}>|null */
    private ?array $patterns = null;

    /** @param array<string, mixed> $settings from config/settings.php */
    public function __construct(public readonly array $settings) {}

    public static function fromRoot(string $rootPath): self
    {
        return new self((require $rootPath . '/config/settings.php')($rootPath));
    }

    public function path(string $name): string
    {
        return $this->settings['paths'][$name];
    }

    /** Creates the writable folders inside data/ (the release zip ships data/ empty). */
    public function ensureDataFolders(): void
    {
        foreach (['cache', 'logs', 'uploads', 'updates', 'backups'] as $name) {
            $path = $this->path($name);

            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }
    }

    public function databaseExists(): bool
    {
        return is_file($this->path('database'));
    }

    public function isInstalled(): bool
    {
        if (!$this->databaseExists() || !$this->config()->exists()) {
            return false;
        }

        try {
            return !empty($this->settingsRepository()->getArray('install')['completed_at']);
        } catch (\Throwable) {
            return false;
        }
    }

    public function pdo(): PDO
    {
        return $this->once(PDO::class, fn() => Connection::create($this->path('database')));
    }

    public function migrator(): Migrator
    {
        return $this->once(Migrator::class, fn() => new Migrator($this->pdo(), $this->path('migrations')));
    }

    public function config(): AppConfig
    {
        return $this->once(AppConfig::class, fn() => new AppConfig($this->path('config')));
    }

    public function crypto(): Crypto
    {
        return $this->once(Crypto::class, fn() => new Crypto($this->config()));
    }

    public function session(): Session
    {
        return $this->once(Session::class, fn() => new Session($this->settings['session']['name']));
    }

    public function translator(): Translator
    {
        return $this->once(Translator::class, fn() => new Translator(
            [$this->path('lang'), ...$this->modules()->langPaths()],
            'de',
            $this->settings['fallback_locale'],
        ));
    }

    public function twig(): Twig
    {
        return $this->once(Twig::class, function (): Twig {
            $twig = Twig::create($this->path('templates'), [
                'cache' => $this->settings['debug'] ? false : $this->path('cache') . '/twig',
                // After an update the compiled templates in data/cache would be stale otherwise.
                'auto_reload' => true,
                'autoescape' => 'html',
            ]);
            $loader = $twig->getLoader();

            if (!$loader instanceof \Twig\Loader\FilesystemLoader) {
                throw new \LogicException('Bioly needs the Twig filesystem loader.');
            }

            foreach ($this->modules()->templateNamespaces() as $namespace => $folder) {
                $loader->addPath($folder, $namespace);
            }

            $twig->addExtension(new AppExtension(
                $this->translator(),
                $this->icons(),
                $this->uiIcons(),
                $this->modules(),
                $this->session(),
                $this->settings['version'],
                $this->path('public'),
            ));

            return $twig;
        });
    }

    public function settingsRepository(): SettingsRepository
    {
        return $this->once(SettingsRepository::class, fn() => new SettingsRepository($this->pdo()));
    }

    public function users(): UserRepository
    {
        return $this->once(UserRepository::class, fn() => new UserRepository($this->pdo()));
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->once(RateLimiter::class, fn() => new RateLimiter(new RateHitRepository($this->pdo()), $this->crypto()));
    }

    public function blocks(): BlockRepository
    {
        return $this->once(BlockRepository::class, fn() => new BlockRepository($this->pdo()));
    }

    public function pages(): PageRepository
    {
        return $this->once(PageRepository::class, fn() => new PageRepository($this->pdo()));
    }

    public function auth(): Auth
    {
        return $this->once(Auth::class, fn() => new Auth(
            $this->users(),
            new LoginAttemptRepository($this->pdo()),
            $this->session(),
            $this->crypto(),
            $this->settings['login'],
            $this->settings['session']['idle_seconds'],
            $this->settings['session']['max_seconds'],
        ));
    }

    public function mail(): MailService
    {
        return $this->once(MailService::class, fn() => new MailService($this->settingsRepository(), $this->crypto()));
    }

    public function accounts(): AccountService
    {
        return $this->once(AccountService::class, fn() => new AccountService(
            $this->users(),
            new TokenRepository($this->pdo()),
            $this->mail(),
            $this->translator(),
            $this->path('data'),
        ));
    }

    public function icons(): IconRegistry
    {
        return $this->once(IconRegistry::class, fn() => new IconRegistry(
            $this->path('icons'),
            $this->path('originals'),
            $this->path('cache') . '/icons.php',
            $this->ownIcons(...),
        ));
    }

    /**
     * Own icons of the media library for the icon registry.
     *
     * @return list<array{filename: string, label: ?string, color: ?string, svg: string}>
     */
    private function ownIcons(): array
    {
        if (!$this->databaseExists()) {
            return [];
        }

        $icons = [];

        foreach ($this->mediaRepository()->byUsage('icon') as $row) {
            $file = $this->path('uploads') . '/' . $row['filename'];

            if (MediaService::validName((string) $row['filename']) && is_file($file)) {
                $icons[] = [
                    'filename' => (string) $row['filename'],
                    'label' => isset($row['label']) ? (string) $row['label'] : null,
                    'color' => isset($row['color']) ? (string) $row['color'] : null,
                    'svg' => (string) file_get_contents($file),
                ];
            }
        }

        return $icons;
    }

    public function mediaRepository(): MediaRepository
    {
        return $this->once(MediaRepository::class, fn() => new MediaRepository($this->pdo()));
    }

    public function uiIcons(): UiIcons
    {
        return $this->once(UiIcons::class, fn() => new UiIcons($this->path('ui_icons')));
    }

    public function modules(): ModuleRegistry
    {
        return $this->once(ModuleRegistry::class, fn() => new ModuleRegistry($this->path('modules')));
    }

    public function validator(): Validator
    {
        return $this->once(Validator::class, fn() => new Validator($this->icons()));
    }

    public function theme(): Theme
    {
        return $this->once(Theme::class, function (): Theme {
            $patterns = [];

            foreach ($this->patterns() as $pattern) {
                $patterns[$pattern['id']] = ['version' => $pattern['version'], 'size' => $pattern['size']];
            }

            return new Theme($this->icons()->palette(), $patterns);
        });
    }

    public function pageBuilder(): PageBuilder
    {
        return $this->once(PageBuilder::class, fn() => new PageBuilder(
            $this->modules(),
            $this->icons(),
            $this->validator(),
            $this->databaseExists() ? fn(string $file): ?array => $this->mediaRepository()->findByFilename($file) : null,
        ));
    }

    public function draft(): Draft
    {
        return $this->once(Draft::class, fn() => new Draft(
            $this->icons(),
            $this->media(),
            $this->theme(),
            $this->modules(),
            $this->validator(),
            $this->translator(),
        ));
    }

    public function afterUpdate(): AfterUpdate
    {
        return $this->once(AfterUpdate::class, fn() => new AfterUpdate(
            $this->migrator(),
            $this->media(),
            $this->publisher(),
            $this->settingsRepository(),
            (string) $this->settings['version'],
            $this->path('cache') . '/version',
        ));
    }

    public function updater(): Updater
    {
        return $this->once(Updater::class, fn() => new Updater(
            $this->path('root'),
            $this->path('updates'),
            (string) $this->settings['version'],
            $this->backups(),
            $this->settings['env'] === 'dev',
        ));
    }

    public function backups(): Backups
    {
        return $this->once(Backups::class, fn() => new Backups($this->path('backups'), $this->path('root'), $this->pdo(), $this->path('uploads'), $this->path('migrations'), (string) $this->settings['version']));
    }

    public function updateCheck(): UpdateCheck
    {
        return $this->once(UpdateCheck::class, fn() => new UpdateCheck(
            $this->settingsRepository(),
            new ReleaseFeed($this->httpClient()),
            (string) $this->settings['version'],
        ));
    }

    public function httpClient(): HttpClient
    {
        return $this->once(HttpClient::class, fn() => new HttpClient('Bioly/' . $this->settings['version'] . ' (+https://github.com/bioly-app/bioly)'));
    }

    /** Test zips from "make zip" are not signed: only the local test site allows them. */
    public function allowUnsignedUpdates(): bool
    {
        return getenv('BIOLY_ALLOW_UNSIGNED_UPDATES') === '1';
    }

    public function publisher(): Publisher
    {
        return $this->once(Publisher::class, fn() => new Publisher(
            $this->pageBuilder(),
            $this->theme(),
            $this->twig(),
            $this->settingsRepository(),
            $this->blocks(),
            $this->translator(),
            $this->favicon(),
            $this->media(),
            $this->pages(),
            $this->path('cache'),
        ));
    }

    public function media(): MediaService
    {
        return $this->once(MediaService::class, fn() => new MediaService(
            $this->path('uploads'),
            (int) $this->settings['upload_max_bytes'],
            $this->databaseExists() ? $this->mediaRepository() : null,
        ));
    }

    public function favicon(): FaviconService
    {
        return $this->once(FaviconService::class, fn() => new FaviconService($this->icons(), $this->media(), $this->theme()));
    }

    public function legal(): LegalText
    {
        return $this->once(LegalText::class, fn() => new LegalText());
    }

    /** The stored site address (see SiteUrl), or null before it is known. */
    public function siteUrl(): ?string
    {
        $url = $this->settingsRepository()->getArray('site')['url'] ?? null;

        return is_string($url) ? SiteUrl::normalize($url) : null;
    }

    /**
     * Header patterns from public/assets/patterns. The version is a hash of the file,
     * so a changed pattern never comes from an old browser cache.
     *
     * @return list<array{id: string, size: int, version: string}>
     */
    public function patterns(): array
    {
        if ($this->patterns !== null) {
            return $this->patterns;
        }

        $folder = $this->path('patterns');
        $data = json_decode((string) @file_get_contents($folder . '/patterns.json'), true);
        $list = [];

        foreach (is_array($data) ? $data : [] as $entry) {
            $id = is_array($entry) && is_string($entry['id'] ?? null) ? $entry['id'] : '';
            $file = $folder . '/' . $id . '.svg';

            if (preg_match('/^[a-z0-9-]+$/', $id) === 1 && is_file($file)) {
                $list[] = ['id' => $id, 'size' => (int) ($entry['size'] ?? 24), 'version' => substr((string) md5_file($file), 0, 8)];
            }
        }

        return $this->patterns = $list;
    }

    /**
     * One instance per class for the whole request.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param callable(): T $factory
     * @return T
     */
    private function once(string $class, callable $factory): object
    {
        $instance = $this->instances[$class] ??= $factory();

        return $instance instanceof $class ? $instance : throw new LogicException('Wrong instance for ' . $class);
    }
}
