<?php

declare(strict_types=1);

namespace Bioly\Module;

use Bioly\Icon\IconRegistry;

final class RenderContext
{
    public function __construct(
        public readonly IconRegistry $icons,
        public readonly string $iconStyle,
        public readonly int $blockId,
        /** @var (\Closure(string): ?array<string, mixed>)|null finds the media row of a library file */
        private readonly ?\Closure $mediaRow = null,
        /** Time zone of the page (Settings), for dates like the countdown. */
        public readonly string $timezone = 'Europe/Berlin',
        /** Point in time the page is rendered for; tests pass a fixed one. */
        public readonly ?\DateTimeImmutable $now = null,
    ) {}

    public function zone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone($this->timezone);
        } catch (\Exception) {
            return new \DateTimeZone('Europe/Berlin');
        }
    }

    public function now(): \DateTimeImmutable
    {
        return ($this->now ?? new \DateTimeImmutable())->setTimezone($this->zone());
    }

    /**
     * Media library row of an image file, or null when it is unknown or gone.
     *
     * @return array<string, mixed>|null
     */
    public function media(string $file): ?array
    {
        return $this->mediaRow !== null ? ($this->mediaRow)($file) : null;
    }

    /**
     * Icon markup, its palette color and the effective icon style.
     *
     * @return array{html: string, color: string, style: string}
     */
    public function icon(?string $iconId, ?string $styleOverride = null): array
    {
        $icon = $iconId !== null ? $this->icons->get($iconId) : null;

        return [
            'html' => $icon !== null ? $this->icons->html($icon['id']) : '',
            'color' => $icon['color'] ?? 'none',
            'style' => $icon !== null && $icon['color'] === null ? 'original' : ($styleOverride ?: $this->iconStyle),
        ];
    }
}
