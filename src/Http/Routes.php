<?php

declare(strict_types=1);

namespace Bioly\Http;

use Bioly\Http\Controller\Admin\CreditsController;
use Bioly\Http\Controller\Admin\EditorController;
use Bioly\Http\Controller\Admin\LegalController;
use Bioly\Http\Controller\Admin\MediaController;
use Bioly\Http\Controller\Admin\SettingsController;
use Bioly\Http\Controller\AuthController;
use Bioly\Http\Controller\InstallController;
use Bioly\Http\Controller\SiteController;
use Bioly\Http\Middleware\BackendContext;
use Bioly\Http\Middleware\RequireLogin;
use Bioly\Http\Middleware\StartSession;
use Bioly\Services;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

final class Routes
{
    /** @param App<ContainerInterface|null> $app */
    public static function register(App $app, Services $services): void
    {
        $site = new SiteController($services);
        $install = new InstallController($services);
        $auth = new AuthController($services);
        $editor = new EditorController($services);
        $settings = new SettingsController($services);
        $legal = new LegalController($services);
        $media = new MediaController($services);
        $credits = new CreditsController($services);
        $session = new StartSession($services);

        // Public link page: no session, no cookie
        $app->get('/', [$site, 'home']);
        $app->get('/theme.css', [$site, 'theme']);
        $app->get('/impressum', [$site, 'imprint']);
        $app->get('/datenschutz', [$site, 'privacy']);
        $app->get('/api/multilink/{id:[0-9]+}', [$site, 'multilink']);
        $app->get('/favicon.svg', [$site, 'favicon']);
        // Browsers ask for /favicon.ico on pages without an icon link (login, installer).
        $app->get('/favicon.ico', static fn($request, $response) => $response->withHeader('Location', '/favicon.svg')->withStatus(301));
        $app->get('/media/{name:(?:avatar|favicon|image|icon)-[a-f0-9]{16}\\.(?:webp|jpg|svg)}', [$site, 'media']);

        $app->group('/install', function (RouteCollectorProxy $group) use ($install): void {
            $group->get('', [$install, 'show']);
            $group->post('', [$install, 'submit']);
        })->add($session);

        $app->group('/admin', function (RouteCollectorProxy $group) use ($auth, $editor, $settings, $legal, $media, $credits, $services): void {
            $group->get('/login', [$auth, 'loginForm']);
            $group->post('/login', [$auth, 'login']);
            $group->get('/forgot', [$auth, 'forgotForm']);
            $group->post('/forgot/mail', [$auth, 'forgotMail']);
            $group->post('/forgot/ftp', [$auth, 'forgotFtp']);
            $group->post('/forgot/ftp/check', [$auth, 'forgotFtpCheck']);
            $group->get('/reset', [$auth, 'resetForm']);
            $group->post('/reset', [$auth, 'reset']);

            $group->group('', function (RouteCollectorProxy $admin) use ($auth, $editor, $settings, $legal, $media, $credits): void {
                $admin->post('/logout', [$auth, 'logout']);

                $admin->get('', [$editor, 'show']);
                $admin->get('/general', [$editor, 'general']);
                $admin->post('/api/upload/{kind:image|icon}', [$editor, 'upload']);
                $admin->post('/api/legal-preview', [$legal, 'preview']);
                $admin->get('/api/state', [$editor, 'state']);
                // Keeps the login alive while someone works (RequireLogin notes the activity).
                $admin->get('/api/ping', static fn($request, $response) => $response->withStatus(204));
                $admin->post('/api/preview', [$editor, 'storePreview']);
                $admin->post('/api/publish', [$editor, 'publish']);
                $admin->get('/preview', [$editor, 'preview']);
                $admin->get('/preview/theme.css', [$editor, 'previewTheme']);
                $admin->get('/preview/multilink/{key}', [$editor, 'previewMultilink']);

                $admin->get('/settings', [$settings, 'show']);
                $admin->post('/settings/account', [$settings, 'account']);
                $admin->post('/settings/password', [$settings, 'password']);
                $admin->post('/settings/general', [$settings, 'general']);
                $admin->post('/settings/smtp', [$settings, 'smtp']);
                $admin->post('/settings/smtp/verify', [$settings, 'verify']);
                $admin->post('/settings/smtp/resend', [$settings, 'resend']);
                $admin->post('/hint/dismiss', [$settings, 'dismissHint']);

                $admin->get('/media', [$media, 'show']);
                $admin->get('/credits', [$credits, 'show']);
                $admin->post('/media/upload', [$media, 'upload']);
                $admin->post('/media/{id:[0-9]+}/save', [$media, 'save']);
                $admin->post('/media/{id:[0-9]+}/delete', [$media, 'delete']);

                $admin->get('/legal/{type:imprint|privacy}', [$legal, 'show']);
                $admin->post('/legal/{type:imprint|privacy}', [$legal, 'save']);
            })->add(new BackendContext($services))->add(new RequireLogin($services));
        })->add($session);
    }
}
