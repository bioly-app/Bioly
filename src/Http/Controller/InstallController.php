<?php

declare(strict_types=1);

namespace Bioly\Http\Controller;

use Bioly\Service\Auth;
use Bioly\Support\Clock;
use Bioly\Support\SiteUrl;
use Bioly\Theme\Color;
use Bioly\Theme\Theme;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Five steps: system, account, mail, basics, done. Progress lives in the session.
 */
final class InstallController extends Controller
{
    private const STEPS = ['system', 'account', 'mail', 'basics', 'done'];

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->services->session();

        // First visit: start in the browser's language if Bioly has it.
        if (!is_string($session->get('locale'))) {
            $this->useLocale($this->browserLocale($request->getHeaderLine('Accept-Language')));
        }

        return $this->page($response, $this->step());
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->input($request, 'action') === 'language') {
            $this->useLocale($this->input($request, 'locale'));

            return $this->redirect($response, '/install');
        }

        $step = $this->step();
        $errors = match ($step) {
            'system' => $this->submitSystem($request),
            'account' => $this->submitAccount($request),
            'mail' => $this->submitMail($request),
            'basics' => $this->submitBasics($request),
            default => [],
        };

        if ($errors !== []) {
            return $this->page($response->withStatus(422), $step, $errors, $this->body($request));
        }

        // After the last step /install is gone (404), so the result is shown right away.
        return $step === 'basics' ? $this->page($response, 'done') : $this->redirect($response, '/install');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private function page(ResponseInterface $response, string $step, array $errors = [], array $old = []): ResponseInterface
    {
        $data = [
            'step' => $step,
            'steps' => array_slice(self::STEPS, 0, 4),
            'index' => array_search($step, self::STEPS, true),
            'errors' => $errors,
            'old' => $old,
            'locale' => $this->services->translator()->locale(),
            'brand' => Theme::BRAND,
        ];

        if ($step === 'system') {
            $data['checks'] = $this->checks();
            $data['passed'] = !in_array(false, array_column(array_filter($data['checks'], static fn(array $c): bool => $c['required']), 'ok'), true);
        }

        if ($step === 'mail') {
            $data['user'] = $this->installUser();
            $data['smtp'] = $this->services->mail()->publicSettings();
            $data['code_sent'] = (bool) $this->services->session()->get('install_code_sent');
        }

        return $this->render($response, 'installer.twig', $data);
    }

    private function step(): string
    {
        $session = $this->services->session();

        if (!$this->services->databaseExists() || !$this->services->config()->exists()) {
            return 'system';
        }

        $step = $session->get('install_step');

        if ($this->services->users()->count() === 0) {
            return 'account';
        }

        return in_array($step, self::STEPS, true) && $step !== 'system' && $step !== 'account' ? $step : 'mail';
    }

    private function useLocale(string $locale): void
    {
        if (in_array($locale, $this->services->settings['locales'], true)) {
            $this->services->session()->set('locale', $locale);
            $this->services->translator()->setLocale($locale);
        }
    }

    private function browserLocale(string $header): string
    {
        foreach (explode(',', $header) as $part) {
            $code = strtolower(substr(trim(explode(';', $part)[0]), 0, 2));

            if (in_array($code, $this->services->settings['locales'], true)) {
                return $code;
            }
        }

        return 'de';
    }

    /** @return array<string, string> */
    private function submitSystem(ServerRequestInterface $request): array
    {
        foreach ($this->checks() as $check) {
            if ($check['required'] && !$check['ok']) {
                return ['system' => 'install.system.failed'];
            }
        }

        $this->services->ensureDataFolders();
        $this->services->config()->create();
        $this->services->migrator()->migrate();
        $settings = $this->services->settingsRepository();
        $settings->set('theme', Theme::DEFAULTS);
        $settings->set('site', [
            'name' => '',
            'bio' => '',
            'locale' => $this->services->translator()->locale(),
            'timezone' => 'Europe/Berlin',
            'quicklinks' => [],
        ]);
        $this->services->pages()->ensure('imprint', 'impressum', $this->t('legal.imprint'));
        $this->services->pages()->ensure('privacy', 'datenschutz', $this->t('legal.privacy'));
        $this->services->session()->set('install_step', 'account');

        return [];
    }

    /** @return array<string, string> */
    private function submitAccount(ServerRequestInterface $request): array
    {
        $username = $this->input($request, 'username');
        $email = $this->input($request, 'email');
        $password = (string) ($this->body($request)['password'] ?? '');
        $errors = [];

        if (preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $username) !== 1) {
            $errors['username'] = 'validation.username';
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'validation.email';
        }

        $problem = Auth::passwordProblem($password, (string) ($this->body($request)['password_repeat'] ?? ''), [$username, $email]);

        if ($problem !== null) {
            $errors['password'] = $problem;
        }

        if ($errors !== []) {
            return $errors;
        }

        $id = $this->services->users()->create($username, Auth::hash($password), $email ?: null, $this->services->translator()->locale());
        $this->services->session()->set('install_user', $id);
        // The person installing is the owner: the address they use becomes the site address.
        $this->services->settingsRepository()->merge('site', ['url' => SiteUrl::fromRequest($request)]);
        $this->services->session()->set('install_step', 'mail');

        return [];
    }

    /** @return array<string, string> */
    private function submitMail(ServerRequestInterface $request): array
    {
        $action = $this->input($request, 'action');
        $session = $this->services->session();
        $user = $this->installUser();
        $accounts = $this->services->accounts();

        if ($action === 'skip') {
            $session->set('install_step', 'basics');

            return [];
        }

        if ($action === 'verify') {
            $problem = $accounts->verifyCode($user, $this->input($request, 'code'));

            if ($problem !== null) {
                return ['code' => $problem];
            }

            $session->remove('install_code_sent');
            $session->set('install_step', 'basics');

            return [];
        }

        if ($action === 'resend') {
            $problem = $accounts->sendCode($user);

            return $problem !== null ? ['code' => $problem] : [];
        }

        $email = $this->input($request, 'email');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['email' => 'validation.email'];
        }

        $this->services->users()->update((int) $user['id'], ['email' => $email, 'email_verified_at' => null]);
        $errors = $this->services->mail()->save(array_map('strval', $this->body($request)));

        if ($errors !== []) {
            return $errors;
        }

        $problem = $accounts->sendCode($this->installUser());

        if ($problem !== null) {
            return ['smtp' => $problem];
        }

        $session->set('install_code_sent', true);

        return [];
    }

    /** @return array<string, string> */
    private function submitBasics(ServerRequestInterface $request): array
    {
        $settings = $this->services->settingsRepository();

        if ($this->input($request, 'action') !== 'skip') {
            $name = $this->input($request, 'name');
            $accent = $this->input($request, 'accent');

            if (mb_strlen($name) > 80) {
                return ['name' => 'validation.max'];
            }

            $settings->merge('site', ['name' => $name, 'bio' => mb_substr($this->input($request, 'bio'), 0, 300)]);

            if (Color::isHex($accent)) {
                $settings->merge('theme', ['accent' => strtoupper($accent)]);
            }
        }

        // The language chosen last in the installer becomes the backend and page language.
        $locale = $this->services->translator()->locale();
        $this->services->users()->update((int) $this->installUser()['id'], ['locale' => $locale]);
        $settings->merge('site', ['locale' => $locale]);
        $settings->set('install', ['completed_at' => Clock::now(), 'version' => $this->services->settings['version']]);
        $this->services->publisher()->publish();
        $this->services->auth()->login((int) $this->installUser()['id']);
        $this->services->session()->remove('install_step');

        return [];
    }

    /** @return array<string, mixed> */
    private function installUser(): array
    {
        return $this->services->users()->admin() ?? [];
    }

    /** @return list<array{label: string, ok: bool, required: bool, value: string}> */
    private function checks(): array
    {
        $data = $this->services->path('data');
        $sqlite = extension_loaded('pdo_sqlite')
            ? (string) ((new PDO('sqlite::memory:'))->query('SELECT sqlite_version()') ?: null)?->fetchColumn()
            : '';

        return [
            ['label' => 'PHP 8.3+', 'ok' => version_compare(PHP_VERSION, '8.3.0', '>='), 'required' => true, 'value' => PHP_VERSION],
            ['label' => 'SQLite 3.37+', 'ok' => $sqlite !== '' && version_compare($sqlite, '3.37.0', '>='), 'required' => true, 'value' => $sqlite ?: '?'],
            ['label' => 'pdo_sqlite, mbstring, sodium, fileinfo', 'ok' => $this->extensions(['pdo_sqlite', 'mbstring', 'sodium', 'fileinfo']), 'required' => true, 'value' => ''],
            ['label' => 'gd, zip, intl', 'ok' => $this->extensions(['gd', 'zip', 'intl']), 'required' => false, 'value' => ''],
            ['label' => 'data/', 'ok' => is_dir($data) && is_writable($data), 'required' => true, 'value' => ''],
            ['label' => 'Upload', 'ok' => true, 'required' => false, 'value' => (string) ini_get('upload_max_filesize')],
        ];
    }

    /** @param list<string> $names */
    private function extensions(array $names): bool
    {
        return array_filter($names, static fn(string $name): bool => !extension_loaded($name)) === [];
    }
}
