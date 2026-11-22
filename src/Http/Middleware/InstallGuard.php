<?php

declare(strict_types=1);

namespace Bioly\Http\Middleware;

use Bioly\Services;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Response;

/**
 * Not installed: everything leads to /install. Installed: /install is gone (404).
 */
final class InstallGuard implements MiddlewareInterface
{
    public function __construct(private readonly Services $services) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $isInstallRoute = str_starts_with($request->getUri()->getPath(), '/install');
        $installed = $this->services->isInstalled();

        if (!$installed && !$isInstallRoute) {
            return (new Response(302))->withHeader('Location', '/install');
        }

        if ($installed && $isInstallRoute) {
            throw new HttpNotFoundException($request);
        }

        return $handler->handle($request);
    }
}
