<?php

declare(strict_types=1);

namespace Bioly\Http\Middleware;

use Bioly\Services;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Starts the session for backend and installer and checks the CSRF token
 * on every request that changes something.
 */
final class StartSession implements MiddlewareInterface
{
    public function __construct(private readonly Services $services) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $this->services->session();
        $session->start($request->getUri()->getScheme() === 'https');

        if (!in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $body = $request->getParsedBody();
            $sent = is_array($body) ? (string) ($body['_csrf'] ?? '') : '';
            $sent = $sent !== '' ? $sent : $request->getHeaderLine('X-CSRF-Token');

            if (!hash_equals($session->csrfToken(), $sent)) {
                $response = new Response(419);
                $response->getBody()->write('The form has expired. Please go back and reload the page.');

                return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
            }
        }

        $locale = $session->get('locale');

        // Login and reset pages: use the language of the (only) account until someone picks another.
        if (!is_string($locale) && $this->services->isInstalled()) {
            $locale = $this->services->users()->admin()['locale'] ?? null;
        }

        if (is_string($locale) && in_array($locale, $this->services->settings['locales'], true)) {
            $this->services->translator()->setLocale($locale);
        }

        return $handler->handle($request);
    }
}
