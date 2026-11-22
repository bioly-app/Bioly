<?php

declare(strict_types=1);

namespace Bioly\Http\Middleware;

use Bioly\Services;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The first request after an update brings database and page up to date (see AfterUpdate). */
final class RunAfterUpdate implements MiddlewareInterface
{
    public function __construct(private readonly Services $services) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->services->isInstalled()) {
            $update = $this->services->afterUpdate();

            if ($update->needed()) {
                $update->run();
            }
        }

        return $handler->handle($request);
    }
}
