<?php

declare(strict_types=1);

namespace Bioly\Page;

use Bioly\I18n\Translator;
use Bioly\Repository\BlockRepository;
use Bioly\Repository\PageRepository;
use Bioly\Repository\SettingsRepository;
use Bioly\Service\FaviconService;
use Bioly\Service\LegalText;
use Bioly\Service\MediaService;
use Bioly\Support\Clock;
use Bioly\Support\SiteUrl;
use Bioly\Theme\Theme;
use Slim\Views\Twig;

/**
 * Renders the link page once and keeps the result in data/cache.
 * Visitors get the cached HTML; it holds no multilink links.
 */
final class Publisher
{
    public function __construct(
        private readonly PageBuilder $builder,
        private readonly Theme $theme,
        private readonly Twig $twig,
        private readonly SettingsRepository $settings,
        private readonly BlockRepository $blocks,
        private readonly Translator $translator,
        private readonly FaviconService $favicon,
        private readonly MediaService $media,
        private readonly PageRepository $pages,
        private readonly string $cachePath,
    ) {}

    /** Everything publish() writes into the cache folder. */
    private const FILES = ['page.html', 'theme.css', 'favicon.svg', 'legal-imprint.html', 'legal-privacy.html'];

    public function publish(): void
    {
        $theme = $this->currentTheme();
        $site = $this->settings->getArray('site');
        $css = $this->theme->css($theme, '/assets/patterns');
        $hash = substr(md5($css), 0, 10);
        $favicon = $this->favicon->svg($theme, (string) ($site['name'] ?? ''));
        $faviconHash = substr(md5($favicon), 0, 10);

        $html = $this->render($site, $theme, $this->blocks->tree(true), [
            'theme_url' => '/theme.css?v=' . $hash,
            'favicon_url' => '/favicon.svg?v=' . $faviconHash,
            'multilink_endpoint' => '/api/multilink/',
        ]);

        $this->write('theme.css', $css);
        $this->write('favicon.svg', $favicon);
        $this->write('page.html', $html);
        $this->publishLegal($site, '/theme.css?v=' . $hash, '/favicon.svg?v=' . $faviconHash);
        $this->settings->set('publish', ['at' => Clock::now(), 'theme' => $hash, 'favicon' => $faviconHash]);
        $this->media->cleanup(array_values(array_filter([
            is_string($site['avatar'] ?? null) ? $site['avatar'] : null,
            $theme['favicon']['file'],
        ])));
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $theme normalized
     * @param list<array<string, mixed>> $blocks
     * @param array<string, string> $urls theme_url, multilink_endpoint
     */
    public function render(array $profile, array $theme, array $blocks, array $urls, bool $preview = false): string
    {
        $stored = $this->settings->getArray('site');
        $this->builder->setTimezone((string) ($stored['timezone'] ?? 'Europe/Berlin'));
        $vars = $this->builder->build($profile, $theme, $blocks);
        // The draft of the editor has no locale; the page language comes from the settings.
        $locale = (string) ($profile['locale'] ?? $stored['locale'] ?? 'de');
        // Absolute address for canonical and Open Graph (link previews need full URLs).
        $siteUrl = SiteUrl::normalize((string) ($stored['url'] ?? ''));

        return $this->fetchIn($locale, 'frontend/page.twig', ['favicon_url' => null, ...$vars, ...$urls, 'site_url' => $siteUrl, 'preview' => $preview]);
    }

    /**
     * Imprint and privacy as finished pages, like the link page. An external page
     * replaces Bioly's own one, so its file is removed.
     *
     * @param array<string, mixed> $site
     */
    private function publishLegal(array $site, string $themeUrl, string $faviconUrl): void
    {
        $external = LegalText::externalLinks($site['legal'] ?? null);

        foreach (LegalText::TYPES as $type) {
            $page = $this->pages->byType($type);
            $file = 'legal-' . $type . '.html';

            if ($page === null || isset($external[$type])) {
                @unlink($this->cachePath . '/' . $file);
                continue;
            }

            $this->write($file, $this->fetchIn((string) ($site['locale'] ?? 'de'), 'frontend/legal.twig', [
                'page' => $page,
                'site' => $site,
                'theme_url' => $themeUrl,
                'favicon_url' => $faviconUrl,
            ]));
        }
    }

    /**
     * Renders in the language of the page and switches back afterwards (the backend
     * may use another language).
     *
     * @param array<string, mixed> $vars
     */
    private function fetchIn(string $locale, string $template, array $vars): string
    {
        $previous = $this->translator->locale();
        $this->translator->setLocale($locale);

        try {
            return $this->twig->fetch($template, [...$vars, 'locale' => $locale]);
        } finally {
            $this->translator->setLocale($previous);
        }
    }

    /** @return array<string, mixed> */
    public function currentTheme(): array
    {
        return $this->theme->normalize($this->settings->getArray('theme'));
    }

    public function cached(string $file): ?string
    {
        $path = $this->cachePath . '/' . $file;

        return in_array($file, self::FILES, true) && is_file($path) ? (string) file_get_contents($path) : null;
    }

    /** A published file; publishes first when the cache is empty (e.g. after clearing it). */
    public function file(string $file): ?string
    {
        if ($this->cached('page.html') === null) {
            $this->publish();
        }

        return $this->cached($file);
    }

    public function clear(): void
    {
        foreach (self::FILES as $file) {
            @unlink($this->cachePath . '/' . $file);
        }
    }

    private function write(string $file, string $content): void
    {
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0775, true);
        }

        $tmp = $this->cachePath . '/' . $file . '.tmp';
        file_put_contents($tmp, $content, LOCK_EX);
        rename($tmp, $this->cachePath . '/' . $file);
    }
}
