<?php

declare(strict_types=1);

namespace Bioly\Http\Middleware;

use Bioly\Services;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class RequireLogin implements MiddlewareInterface
{
    public function __construct(private readonly Services $services) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->services->auth()->user();

        if ($user === null) {
            if (str_starts_with($request->getUri()->getPath(), '/admin/api/')) {
                $response = new Response(401);
                $response->getBody()->write('{"error":"logged_out"}');

                return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            return (new Response(302))->withHeader('Location', '/admin/login');
        }

        $this->services->translator()->setLocale($user['locale']);

        return $handler->handle($request->withAttribute('user', $user));
    }
}
