<?php

declare(strict_types=1);

namespace Bioly\Http\Middleware;

use Bioly\Services;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The first request after an update brings database and page up to date (see AfterUpdate).
 * If that fails after an update from the backend, the backup goes back in (Updater).
 */
final class RunAfterUpdate implements MiddlewareInterface
{
    public function __construct(private readonly Services $services) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->services->isInstalled()) {
            $update = $this->services->afterUpdate();

            if ($update->needed()) {
                $updater = $this->services->updater();

                try {
                    $update->run();
                    $updater->finishOk();

                    // Publishing the page used Twig. Start the request again with a fresh Twig,
                    // so the backend can still add its globals (they must come before rendering).
                    $uri = $request->getUri();
                    $target = $request->getMethod() === 'GET' ? $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '') : '/admin';

                    return (new \Slim\Psr7\Response(303))->withHeader('Location', $target);
                } catch (\Throwable $error) {
                    if ($updater->pending() === null) {
                        throw $error;
                    }

                    $updater->finishFailed($error);

                    // The old version is back: start over with it.
                    return (new \Slim\Psr7\Response(303))->withHeader('Location', '/admin/updates');
                }
            }
        }

        return $handler->handle($request);
    }
}
