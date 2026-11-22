<?php

declare(strict_types=1);

namespace Bioly\Http\Controller;

use Bioly\Modules\Multilink\MultilinkModule;
use Bioly\Services;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

abstract class Controller
{
    public function __construct(protected readonly Services $services) {}

    /** @param array<string, mixed> $data */
    protected function render(ResponseInterface $response, string $template, array $data = []): ResponseInterface
    {
        return $this->services->twig()->render($response, $template, $data);
    }

    protected function redirect(ResponseInterface $response, string $url): ResponseInterface
    {
        return $response->withHeader('Location', $url)->withStatus(303);
    }

    protected function json(ResponseInterface $response, mixed $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
    }

    /**
     * Words a new password must not be built from: username, e-mail and site name.
     *
     * @param array<string, mixed> $user
     * @return list<string>
     */
    protected function passwordContext(array $user): array
    {
        $site = $this->services->settingsRepository()->getArray('site');

        return array_values(array_filter([
            (string) ($user['username'] ?? ''),
            (string) ($user['email'] ?? ''),
            (string) ($site['name'] ?? ''),
        ], static fn(string $value): bool => $value !== ''));
    }

    /**
     * Every JSON error has the same shape: {"error": "<code>", ...details}.
     * Clients react only to codes they know and show a neutral message for the rest.
     *
     * @param array<string, mixed> $details
     */
    protected function jsonError(ResponseInterface $response, string $code, int $status, array $details = []): ResponseInterface
    {
        return $this->json($response, ['error' => $code] + $details, $status);
    }

    /**
     * The links of a multilink, for the page and the preview alike: age check first.
     *
     * @param array<string, mixed> $data the multilink's settings
     * @param list<array<string, mixed>> $children its links
     */
    protected function multilinkAnswer(ServerRequestInterface $request, ResponseInterface $response, array $data, array $children, string $iconStyle): ResponseInterface
    {
        if (!MultilinkModule::passesAgeGate($data, $request->getHeaderLine(MultilinkModule::AGE_HEADER))) {
            return $this->jsonError($response, 'age_required', 403, ['min_age' => MultilinkModule::MIN_AGE]);
        }

        $items = $this->services->pageBuilder()->children($children, $iconStyle);

        return $this->json($response, ['html' => $this->services->twig()->fetch('@module_multilink/items.twig', ['items' => $items])]);
    }

    protected function text(ResponseInterface $response, string $content, string $type, int $maxAge = 0): ResponseInterface
    {
        $response->getBody()->write($content);
        $response = $response->withHeader('Content-Type', $type);

        return $maxAge > 0 ? $response->withHeader('Cache-Control', 'public, max-age=' . $maxAge) : $response;
    }

    /** @return array<string, mixed> */
    protected function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    protected function input(ServerRequestInterface $request, string $key): string
    {
        $value = $this->body($request)[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    protected function ip(ServerRequestInterface $request): string
    {
        return (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
    }

    /** @param array<string, string|int|float> $parameters */
    protected function t(string $key, array $parameters = []): string
    {
        return $this->services->translator()->translate($key, $parameters);
    }
}
