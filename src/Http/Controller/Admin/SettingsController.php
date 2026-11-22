<?php

declare(strict_types=1);

namespace Bioly\Http\Controller\Admin;

use Bioly\Http\Controller\Controller;
use Bioly\Service\Auth;
use Bioly\Support\SiteUrl;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Account, password, language and time zone, SMTP. Saved right away (not a draft).
 */
final class SettingsController extends Controller
{
    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->page($request, $response);
    }

    public function account(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $username = $this->input($request, 'username');
        $email = $this->input($request, 'email');
        $errors = [];

        if (preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $username) !== 1) {
            $errors['username'] = 'validation.username';
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'validation.email';
        }

        if ($errors !== []) {
            return $this->page($request, $response->withStatus(422), $errors);
        }

        $values = ['username' => $username, 'email' => $email ?: null];

        if (strcasecmp((string) $user['email'], $email) !== 0) {
            // A new address must be confirmed again.
            $values['email_verified_at'] = null;
            $this->services->mail()->markVerified(null);
        }

        $this->services->users()->update((int) $user['id'], $values);

        return $this->done($response, 'settings.saved');
    }

    public function password(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $body = $this->body($request);

        if (!password_verify((string) ($body['current'] ?? ''), $user['password_hash'])) {
            return $this->page($request, $response->withStatus(422), ['current' => 'validation.password_current']);
        }

        $problem = Auth::passwordProblem((string) ($body['password'] ?? ''), (string) ($body['password_repeat'] ?? ''), $this->passwordContext($user));

        if ($problem !== null) {
            return $this->page($request, $response->withStatus(422), ['password' => $problem]);
        }

        $auth = $this->services->auth();
        $auth->changePassword((int) $user['id'], (string) $body['password']);
        // Other sessions end; this one starts fresh.
        $auth->login((int) $user['id']);

        return $this->done($response, 'settings.password_changed');
    }

    public function general(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $locales = $this->services->settings['locales'];
        $backend = $this->input($request, 'backend_locale');
        $site = $this->input($request, 'site_locale');
        $timezone = $this->input($request, 'timezone');
        $url = SiteUrl::normalize($this->input($request, 'site_url'));

        if ($url === null) {
            return $this->page($request, $response->withStatus(422), ['site_url' => 'settings.site_url.invalid']);
        }

        if (!in_array($backend, $locales, true) || !in_array($site, $locales, true) || !in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            return $this->page($request, $response->withStatus(422), ['general' => 'validation.option']);
        }

        $this->services->users()->update((int) $user['id'], ['locale' => $backend]);
        $this->services->session()->set('locale', $backend);
        $this->services->translator()->setLocale($backend);
        $this->services->settingsRepository()->merge('site', ['locale' => $site, 'timezone' => $timezone, 'url' => $url]);
        $this->services->publisher()->publish();

        return $this->done($response, 'settings.saved');
    }

    public function smtp(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');

        if (($user['email'] ?? '') === '') {
            return $this->page($request, $response->withStatus(422), ['smtp' => 'account.code.no_email']);
        }

        $errors = $this->services->mail()->save(array_map('strval', $this->body($request)));

        if ($errors !== []) {
            return $this->page($request, $response->withStatus(422), $errors);
        }

        $problem = $this->services->accounts()->sendCode($user);

        if ($problem !== null) {
            return $this->page($request, $response->withStatus(422), ['smtp' => $problem]);
        }

        $this->services->session()->set('settings_code_sent', true);

        return $this->done($response, 'settings.code_sent');
    }

    public function verify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $problem = $this->services->accounts()->verifyCode($request->getAttribute('user'), $this->input($request, 'code'));

        if ($problem !== null) {
            return $this->page($request, $response->withStatus(422), ['code' => $problem]);
        }

        $this->services->session()->remove('settings_code_sent');

        return $this->done($response, 'settings.smtp_verified');
    }

    public function resend(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $problem = $this->services->accounts()->sendCode($request->getAttribute('user'));

        if ($problem !== null) {
            return $this->page($request, $response->withStatus(422), ['code' => $problem]);
        }

        return $this->done($response, 'settings.code_sent');
    }

    public function dismissHint(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Only for this session: the hint comes back with the next login.
        $this->services->session()->set('smtp_hint_dismissed', true);
        $back = $this->input($request, 'back');

        return $this->redirect($response, str_starts_with($back, '/admin') ? $back : '/admin');
    }

    /** @param array<string, string> $errors */
    private function page(ServerRequestInterface $request, ResponseInterface $response, array $errors = []): ResponseInterface
    {
        $site = $this->services->settingsRepository()->getArray('site');

        return $this->render($response, 'backend/settings.twig', [
            'errors' => $errors,
            'old' => $this->body($request),
            'smtp' => $this->services->mail()->publicSettings(),
            'smtp_verified' => $this->services->mail()->isVerified(),
            'code_sent' => (bool) $this->services->session()->get('settings_code_sent'),
            'site_locale' => $site['locale'] ?? 'de',
            'timezone' => $site['timezone'] ?? 'Europe/Berlin',
            'timezones' => DateTimeZone::listIdentifiers(),
            'site_url' => $this->services->siteUrl() ?? '',
            'request_url' => SiteUrl::fromRequest($request),
            'locales' => $this->services->settings['locales'],
        ]);
    }

    private function done(ResponseInterface $response, string $message): ResponseInterface
    {
        $this->services->session()->flash('success', $this->t($message));

        return $this->redirect($response, '/admin/settings');
    }
}
