<?php

declare(strict_types=1);

namespace Bioly\Http\Controller\Admin;

use Bioly\Http\Controller\Controller;
use Bioly\Project;
use Bioly\Update\Package;
use Bioly\Update\PublicKey;
use Bioly\Update\UpdateException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * Updates: installed version, check for a new version on GitHub, install it (online or
 * from an uploaded zip), backups with restore.
 */
final class UpdatesController extends Controller
{
    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $check = $this->services->updateCheck();

        // Opening the page asks GitHub, at most once a day.
        if ($check->due()) {
            $check->run();
        }

        $updater = $this->services->updater();
        $failure = $updater->takeFailure();

        if ($failure !== null) {
            $this->services->session()->flash('error', $this->t('update.failed', ['from' => $failure['from'], 'to' => $failure['to']]));
        }

        $state = $check->state();

        return $this->render($response, 'backend/updates.twig', [
            'state' => $state,
            'auto' => $check->enabled(),
            'blockers' => array_map(fn(string $key): string => $this->t($key), $updater->blockers()),
            'backups' => $this->services->backups()->all(),
            'releases_url' => Project::RELEASES_URL,
            'php_version' => PHP_VERSION,
            'sqlite_version' => $this->sqliteVersion(),
            'upload_max_mb' => round($this->uploadLimit() / 1048576, 1),
            'timezone' => (string) ($this->services->settingsRepository()->getArray('site')['timezone'] ?? 'Europe/Berlin'),
        ]);
    }

    /** "Check now" on the page. */
    public function check(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $check = $this->services->updateCheck();
        $check->run();
        $state = $check->state();
        $session = $this->services->session();

        if ($state['error'] !== null) {
            $session->flash('error', $this->t($state['error']));
        } elseif ($state['available'] && $state['latest'] !== null) {
            $session->flash('success', $this->t('update.found', ['version' => $state['latest']['version']]));
        } else {
            $session->flash('success', $this->t('update.none'));
        }

        return $this->redirect($response, '/admin/updates');
    }

    /** Quiet check from any backend page, once a day (backend.js). */
    public function auto(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $check = $this->services->updateCheck();

        if ($check->due()) {
            $check->run();
        }

        return $this->json($response, ['available' => $check->state()['available']]);
    }

    public function settings(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->services->updateCheck()->setEnabled($this->input($request, 'auto') === '1');
        $this->services->session()->flash('success', $this->t('update.settings.saved'));

        return $this->redirect($response, '/admin/updates');
    }

    /** Download the newest release from GitHub and install it. */
    public function install(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $state = $this->services->updateCheck()->state();

        if (!$state['available'] || $state['latest'] === null) {
            return $this->fail($response, 'update.error.nothing');
        }

        $updater = $this->services->updater();
        $zip = $this->services->path('updates') . '/download-' . bin2hex(random_bytes(4)) . '.zip';

        try {
            $this->services->httpClient()->get($state['latest']['zip'], [], $zip, 80 * 1024 * 1024, 120);
        } catch (Throwable) {
            @unlink($zip);

            return $this->fail($response, 'update.error.download');
        }

        return $this->installZip($response, $zip);
    }

    /** Install from a zip uploaded on the page (servers without downloads, or for testing). */
    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $file = $request->getUploadedFiles()['zip'] ?? null;

        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->fail($response, $file instanceof UploadedFileInterface && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'update.error.too_large' : 'update.error.no_file');
        }

        $zip = $this->services->path('updates') . '/download-' . bin2hex(random_bytes(4)) . '.zip';
        $file->moveTo($zip);

        return $this->installZip($response, $zip);
    }

    /** @param array<string, string> $args */
    public function restore(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (string) ($args['id'] ?? '');
        $backup = $this->services->backups()->find($id);
        $blockers = $this->services->updater()->blockers();

        // A backup from an update also puts program files back: not where updates are blocked.
        if ($backup !== null && $backup['kind'] === 'update' && $blockers !== []) {
            return $this->fail($response, $blockers[0]);
        }

        try {
            $this->services->backups()->restore($id);
        } catch (UpdateException $exception) {
            return $this->fail($response, $exception->getMessage());
        }

        $this->services->session()->flash('success', $this->t('update.restored'));

        return $this->redirect($response, '/admin/updates');
    }

    /** "Create backup now": program files, database and media library. */
    public function backup(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $this->services->backups()->create(true);
        } catch (Throwable) {
            return $this->fail($response, 'update.backup.failed');
        }

        $this->services->session()->flash('success', $this->t('update.backup.done'));

        return $this->redirect($response, '/admin/updates#u-backups');
    }

    /** Upload a downloaded backup (from here or another installation); it then shows in the list. */
    public function uploadBackup(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $file = $request->getUploadedFiles()['backup'] ?? null;

        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->fail($response, $file instanceof UploadedFileInterface && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'update.error.too_large' : 'update.error.no_file');
        }

        $zip = $this->services->path('updates') . '/backup-' . bin2hex(random_bytes(4)) . '.zip';
        $file->moveTo($zip);

        try {
            $this->services->backups()->import($zip);
        } catch (UpdateException $exception) {
            return $this->fail($response, $exception->getMessage());
        } finally {
            @unlink($zip);
        }

        $this->services->session()->flash('success', $this->t('update.backup.uploaded'));

        return $this->redirect($response, '/admin/updates#u-backups');
    }

    /** @param array<string, string> $args */
    public function download(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (string) ($args['id'] ?? '');
        $file = tempnam(sys_get_temp_dir(), 'bioly-backup-');

        try {
            $this->services->backups()->zip($id, (string) $file);
        } catch (Throwable) {
            @unlink((string) $file);

            return $this->fail($response, 'update.error.no_backup');
        }

        // The temporary zip goes away once it is sent.
        register_shutdown_function(static fn() => @unlink((string) $file));
        $stream = (new \Slim\Psr7\Factory\StreamFactory())->createStreamFromFile((string) $file);

        return $response
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="bioly-backup-' . $id . '.zip"')
            ->withHeader('Content-Length', (string) filesize((string) $file))
            ->withBody($stream);
    }

    /** @param array<string, string> $args */
    public function deleteBackup(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->services->backups()->delete((string) ($args['id'] ?? ''));
        $this->services->session()->flash('success', $this->t('update.backup.deleted'));

        return $this->redirect($response, '/admin/updates#u-backups');
    }

    private function installZip(ResponseInterface $response, string $zip): ResponseInterface
    {
        $updater = $this->services->updater();

        try {
            $package = Package::open($zip, $updater->workFolder(), PublicKey::VALUE, $this->services->allowUnsignedUpdates());
            $updater->install($package);
        } catch (UpdateException $exception) {
            return $this->fail($response, $exception->getMessage());
        } finally {
            $updater->cleanUp();
            @unlink($zip);
        }

        $this->services->session()->flash('success', $this->t('update.installed', ['version' => $package->version]));

        // The next request runs the new version for the first time (migrations, new page).
        return $this->redirect($response, '/admin/updates');
    }

    private function fail(ResponseInterface $response, string $key): ResponseInterface
    {
        $this->services->session()->flash('error', $this->t($key));

        return $this->redirect($response, '/admin/updates');
    }

    private function sqliteVersion(): string
    {
        $statement = $this->services->pdo()->query('select sqlite_version()');

        return $statement !== false ? (string) $statement->fetchColumn() : '?';
    }

    private function uploadLimit(): int
    {
        $toBytes = static function (string $value): int {
            $number = (int) $value;

            return match (strtolower(substr(trim($value), -1))) {
                'g' => $number * 1024 ** 3,
                'm' => $number * 1024 ** 2,
                'k' => $number * 1024,
                default => $number,
            };
        };

        return min($toBytes((string) ini_get('upload_max_filesize')), $toBytes((string) ini_get('post_max_size')));
    }
}
