<?php

declare(strict_types=1);

namespace Bioly\Http;

use Bioly\Services;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;
use Throwable;

/**
 * 404 and 500 pages. Details only in debug mode; errors go to data/logs/app.log.
 */
final class ErrorHandler
{
    public function __construct(
        private readonly Services $services,
        private readonly ResponseFactoryInterface $responses,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
    ): ResponseInterface {
        $status = $exception instanceof HttpException ? $exception->getCode() : 500;
        $status = $status === 405 ? 404 : $status;

        if ($status >= 500) {
            $this->log($exception, $request);
        }

        $response = $this->responses->createResponse($status);

        try {
            return $this->services->twig()->render($response, 'error.twig', [
                'status' => $status,
                'details' => $displayErrorDetails && $status >= 500 ? (string) $exception : null,
                'theme_url' => $this->services->isInstalled() ? '/theme.css' : null,
            ]);
        } catch (Throwable) {
            $response->getBody()->write($status === 404 ? 'Not found' : 'Something went wrong.');

            return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }
    }

    private function log(Throwable $exception, ServerRequestInterface $request): void
    {
        $line = sprintf(
            "[%s] %s %s: %s in %s:%d\n",
            gmdate('Y-m-d H:i:s'),
            $request->getMethod(),
            $request->getUri()->getPath(),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
        );
        @file_put_contents($this->services->path('logs') . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }
}
