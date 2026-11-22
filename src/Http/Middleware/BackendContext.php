<?php

declare(strict_types=1);

namespace Bioly\Http\Middleware;

use Bioly\Services;
use Bioly\Support\SiteUrl;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Everything the backend layout needs, for the logged-in owner (runs after RequireLogin).
 */
final class BackendContext implements MiddlewareInterface
{
    public function __construct(private readonly Services $services) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var array<string, mixed> $user */
        $user = $request->getAttribute('user');
        $settings = $this->services->settingsRepository();

        // Installations from before 0.1.6 have no site address yet: take the one the owner uses.
        if ($this->services->siteUrl() === null) {
            $settings->merge('site', ['url' => SiteUrl::fromRequest($request)]);
        }

        $avatar = (string) ($settings->getArray('site')['avatar'] ?? '');
        $twig = $this->services->twig()->getEnvironment();
        $twig->addGlobal('user', $user);
        $twig->addGlobal('smtp_hint', !$this->services->mail()->isVerified() && !$this->services->session()->get('smtp_hint_dismissed'));
        $twig->addGlobal('current_path', $request->getUri()->getPath());
        $twig->addGlobal('avatar_url', $this->services->media()->exists($avatar) ? '/media/' . $avatar : null);
        $twig->addGlobal('session_info', ['idle' => $this->services->auth()->idleSeconds(), 'ends_at' => $this->services->auth()->sessionEndsAt()]);
        $twig->addGlobal('favicon_version', (string) ($settings->getArray('publish')['favicon'] ?? '0'));

        return $handler->handle($request);
    }
}
