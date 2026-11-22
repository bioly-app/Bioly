<?php

declare(strict_types=1);

namespace Bioly\Http\Controller;

use Bioly\Service\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuthController extends Controller
{
    public function loginForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->services->auth()->user() !== null) {
            return $this->redirect($response, '/admin');
        }

        $auth = $this->services->auth();

        return $this->render($response, 'auth/login.twig', [
            'lock_seconds' => $auth->lockSeconds('', $this->ip($request)),
            'lock_minutes' => $auth->lockMinutes(),
        ]);
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $auth = $this->services->auth();
        $identifier = $this->input($request, 'username');
        $password = (string) ($this->body($request)['password'] ?? '');
        $result = $auth->attempt($identifier, $password, $this->ip($request));

        if ($result === Auth::OK) {
            return $this->redirect($response, '/admin');
        }

        $lock = $auth->lockSeconds($identifier, $this->ip($request));

        return $this->render($response->withStatus($lock > 0 ? 429 : 422), 'auth/login.twig', [
            'error' => $lock > 0 ? null : 'auth.invalid',
            'lock_seconds' => $lock,
            'lock_minutes' => $auth->lockMinutes(),
            'username' => $identifier,
        ]);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->services->auth()->logout();

        // The browser also forgets cached backend pages and stored data of this site.
        return $this->redirect($response, '/admin/login')->withHeader('Clear-Site-Data', '"cache", "cookies", "storage"');
    }

    public function forgotForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $mailAvailable = $this->services->mail()->isVerified();

        return $this->render($response, 'auth/forgot.twig', [
            'via' => ($query['via'] ?? '') === 'mail' && $mailAvailable ? 'mail' : (($query['via'] ?? '') === 'ftp' || !$mailAvailable ? 'ftp' : 'mail'),
            'mail_available' => $mailAvailable,
            'sent' => isset($query['sent']),
            'wait' => max(0, (int) ($query['wait'] ?? 0)),
            'ftp_file' => $this->services->session()->get('ftp_reset_file'),
            'error' => $query['error'] ?? null,
        ]);
    }

    public function forgotMail(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // The link is built from the stored site address only, never from the Host header.
        $site = $this->services->siteUrl();

        if ($site === null) {
            return $this->redirect($response, '/admin/forgot?via=ftp');
        }

        $wait = $this->services->accounts()->requestMailReset($site . '/admin/reset');

        return $this->redirect($response, '/admin/forgot?via=mail&' . ($wait > 0 ? 'wait=' . $wait : 'sent=1'));
    }

    public function forgotFtp(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->services->session()->set('ftp_reset_file', $this->services->accounts()->startFtpReset());

        return $this->redirect($response, '/admin/forgot?via=ftp');
    }

    public function forgotFtpCheck(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->services->session();
        $userId = $this->services->accounts()->checkFtpReset((string) $session->get('ftp_reset_file', ''));

        if ($userId === null) {
            return $this->redirect($response, '/admin/forgot?via=ftp&error=1');
        }

        $session->remove('ftp_reset_file');
        $session->set('reset_user', $userId);

        return $this->redirect($response, '/admin/reset');
    }

    public function resetForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->services->session();
        $token = (string) ($request->getQueryParams()['token'] ?? '');

        if ($token !== '') {
            $userId = $this->services->accounts()->consumeMailReset($token);

            if ($userId === null) {
                return $this->redirect($response, '/admin/forgot?via=mail&error=1');
            }

            $session->set('reset_user', $userId);

            // Reload without the token in the address bar.
            return $this->redirect($response, '/admin/reset');
        }

        if (!is_int($session->get('reset_user'))) {
            return $this->redirect($response, '/admin/forgot');
        }

        return $this->render($response, 'auth/reset.twig');
    }

    public function reset(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->services->session();
        $userId = $session->get('reset_user');

        if (!is_int($userId)) {
            return $this->redirect($response, '/admin/forgot');
        }

        $body = $this->body($request);
        $user = $this->services->users()->find($userId) ?? [];
        $problem = Auth::passwordProblem((string) ($body['password'] ?? ''), (string) ($body['password_repeat'] ?? ''), $this->passwordContext($user));

        if ($problem !== null) {
            return $this->render($response->withStatus(422), 'auth/reset.twig', ['error' => $problem]);
        }

        $this->services->auth()->changePassword($userId, (string) $body['password']);
        $session->remove('reset_user');
        $session->flash('success', $this->t('auth.reset.done'));

        return $this->redirect($response, '/admin/login');
    }
}
