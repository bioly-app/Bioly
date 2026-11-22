<?php

declare(strict_types=1);

namespace Bioly\Twig;

use Bioly\I18n\Translator;
use Bioly\Icon\IconRegistry;
use Bioly\Icon\UiIcons;
use Bioly\Module\ModuleRegistry;
use Bioly\Service\InAppBrowser;
use Bioly\Support\Session;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class AppExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly Translator $translator,
        private readonly IconRegistry $icons,
        private readonly UiIcons $uiIcons,
        private readonly ModuleRegistry $modules,
        private readonly Session $session,
        private readonly string $version,
        private readonly string $publicPath = '',
    ) {}

    /**
     * Version for asset URLs: the Bioly version plus a short hash of the file's change time,
     * so a changed file is never served from an old browser cache (CSS and JS are cached a year).
     */
    private function asset(string $path): string
    {
        $time = $this->publicPath !== '' ? @filemtime($this->publicPath . $path) : false;

        return $path . '?v=' . rawurlencode($this->version) . ($time !== false ? '.' . substr(md5((string) $time), 0, 8) : '');
    }

    public function getFilters(): array
    {
        return [
            // {{ 'backend.save'|t }} or {{ 'mail.code.body'|t({code: code}) }}
            new TwigFilter('t', $this->translator->translate(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('icon', $this->icons->html(...), ['is_safe' => ['html']]),
            // {{ ui_icon('x') }} or {{ ui_icon('house', 'nav__icon') }}: backend icons from resources/ui-icons
            new TwigFunction('ui_icon', $this->uiIcons->html(...), ['is_safe' => ['html']]),
            new TwigFunction('module_icon', $this->modules->iconSvg(...), ['is_safe' => ['html']]),
            new TwigFunction('current_locale', $this->translator->locale(...)),
            new TwigFunction('asset', $this->asset(...)),
            new TwigFunction('in_app_pattern', static fn(): string => InAppBrowser::PATTERN),
            new TwigFunction('csrf_token', fn(): string => session_status() === PHP_SESSION_ACTIVE ? $this->session->csrfToken() : ''),
            new TwigFunction('flashes', fn(): array => session_status() === PHP_SESSION_ACTIVE ? $this->session->pullFlashes() : []),
        ];
    }

    public function getGlobals(): array
    {
        return ['app' => ['version' => $this->version, 'feedback_url' => \Bioly\Project::FEEDBACK_URL]];
    }
}
