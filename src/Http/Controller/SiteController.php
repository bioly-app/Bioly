<?php

declare(strict_types=1);

namespace Bioly\Http\Controller;

use Bioly\Service\InAppBrowser;
use Bioly\Service\LegalText;
use Bioly\Service\MediaService;
use Bioly\Service\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

final class SiteController extends Controller
{
    public function home(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $html = (string) $this->services->publisher()->file('page.html');

        return $this->text($response, $html, 'text/html; charset=utf-8')->withHeader('Cache-Control', 'no-cache');
    }

    public function theme(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $css = (string) $this->services->publisher()->file('theme.css');

        return $this->text($response, $css, 'text/css; charset=utf-8', isset($request->getQueryParams()['v']) ? 31536000 : 300);
    }

    public function favicon(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $svg = (string) $this->services->publisher()->file('favicon.svg');

        return self::svgHeaders($this->text($response, $svg, 'image/svg+xml', isset($request->getQueryParams()['v']) ? 31536000 : 300));
    }

    /**
     * Uploaded files from data/uploads; names are random, so they can be cached for good.
     *
     * @param array<string, string> $args
     */
    public function media(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $name = (string) $args['name'];
        $content = $this->services->media()->contents($name) ?? throw new HttpNotFoundException($request);
        $type = MediaService::contentType($name);
        $response = $this->text($response, $content, $type)->withHeader('Cache-Control', 'public, max-age=31536000, immutable');

        return $type === 'image/svg+xml' ? self::svgHeaders($response) : $response;
    }

    /** An SVG opened on its own must not run anything, even if the cleaner missed something. */
    private static function svgHeaders(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox")
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    public function imprint(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->legal($request, $response, 'imprint');
    }

    public function privacy(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->legal($request, $response, 'privacy');
    }

    /**
     * Links of a multilink, never for in-app browsers.
     *
     * @param array<string, string> $args
     */
    public function multilink(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $response = $response->withHeader('Cache-Control', 'no-store')->withHeader('X-Robots-Tag', 'noindex');

        if (InAppBrowser::detect($request->getHeaderLine('User-Agent'))) {
            return $this->jsonError($response, 'in_app', 403);
        }

        if (!$this->services->rateLimiter()->allow(RateLimiter::MULTILINK, $this->ip($request))) {
            return $this->jsonError($response, 'too_many', 429)->withHeader('Retry-After', (string) RateLimiter::MULTILINK['window']);
        }

        $blocks = $this->services->blocks();
        $block = $blocks->findActive((int) $args['id']);

        if ($block === null || $block['type'] !== 'multilink') {
            throw new HttpNotFoundException($request);
        }

        $theme = $this->services->publisher()->currentTheme();

        return $this->multilinkAnswer($request, $response, $block['data'], $blocks->activeChildren($block['id']), $theme['icon_style']);
    }

    private function legal(ServerRequestInterface $request, ResponseInterface $response, string $type): ResponseInterface
    {
        $site = $this->services->settingsRepository()->getArray('site');
        $external = LegalText::externalLinks($site['legal'] ?? null)[$type] ?? '';

        // An external imprint or privacy page: old links and bookmarks lead there.
        if ($external !== '') {
            return $response->withHeader('Location', $external)->withStatus(302);
        }

        $html = $this->services->publisher()->file('legal-' . $type . '.html') ?? throw new HttpNotFoundException($request);

        return $this->text($response, $html, 'text/html; charset=utf-8')->withHeader('Cache-Control', 'no-cache');
    }
}
