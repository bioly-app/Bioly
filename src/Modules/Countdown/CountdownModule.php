<?php

declare(strict_types=1);

namespace Bioly\Modules\Countdown;

use Bioly\Module\AbstractModule;
use Bioly\Module\RenderContext;
use Bioly\Module\Validator;

/**
 * Countdown to the next event. Three states: before the start (countdown), between start
 * and end ("happening now") and after the end (hidden or "over"). The page is a static
 * file, so the state is set once when publishing and kept current by countdown.js.
 */
final class CountdownModule extends AbstractModule
{
    public const DISPLAYS = ['large', 'compact'];
    public const AFTER = ['hide', 'over'];

    public function type(): string
    {
        return 'countdown';
    }

    public function fields(): array
    {
        return [
            ['name' => 'title', 'type' => 'text', 'required' => true, 'max' => 80, 'group' => 'text'],
            ['name' => 'eyebrow', 'type' => 'text', 'max' => 40, 'group' => 'text'],
            ['name' => 'place', 'type' => 'text', 'max' => 60, 'group' => 'text'],
            ['name' => 'start_date', 'type' => 'date', 'required' => true, 'group' => 'time'],
            ['name' => 'start_time', 'type' => 'time', 'pair' => true, 'group' => 'time'],
            ['name' => 'end_date', 'type' => 'date', 'group' => 'time'],
            ['name' => 'end_time', 'type' => 'time', 'pair' => true, 'group' => 'time'],
            ['name' => 'url', 'type' => 'url', 'max' => Validator::MAX_URL, 'group' => 'target'],
            ['name' => 'new_tab', 'type' => 'toggle', 'default' => true, 'group' => 'target'],
            ['name' => 'display', 'type' => 'select', 'options' => self::DISPLAYS, 'default' => 'large', 'group' => 'look'],
            ['name' => 'after', 'type' => 'select', 'options' => self::AFTER, 'default' => 'hide', 'group' => 'look'],
        ];
    }

    public function sortOrder(): int
    {
        return 55;
    }

    public function view(array $data, array $children, RenderContext $context): array
    {
        $zone = $context->zone();
        $startTime = (string) ($data['start_time'] ?? '');
        $start = new \DateTimeImmutable($data['start_date'] . ' ' . ($startTime ?: '00:00'), $zone);
        $end = self::end($start, (string) ($data['end_date'] ?? ''), (string) ($data['end_time'] ?? ''), $zone);
        $now = $context->now();
        $after = in_array($data['after'] ?? '', self::AFTER, true) ? $data['after'] : 'hide';
        $state = $now < $start ? 'upcoming' : ($now < $end ? 'live' : 'over');

        if ($state === 'over' && $after === 'hide') {
            return ['hidden' => true];
        }

        $left = max(0, $start->getTimestamp() - $now->getTimestamp());
        $endTime = (string) ($data['end_time'] ?? '');
        $endsOtherDay = ($data['end_date'] ?? '') !== '' && $data['end_date'] !== $data['start_date'];

        return [
            'hidden' => false,
            'title' => $data['title'],
            'eyebrow' => $data['eyebrow'] ?? '',
            'place' => $data['place'] ?? '',
            'url' => $data['url'] ?? '',
            'new_tab' => !empty($data['new_tab']),
            'display' => in_array($data['display'] ?? '', self::DISPLAYS, true) ? $data['display'] : 'large',
            'after' => $after,
            'state' => $state,
            'start' => self::parts($start),
            'start_time' => $startTime,
            'start_iso' => $start->format(DATE_ATOM),
            'end_iso' => $end->format(DATE_ATOM),
            // "until 23:00" on the same day, "until Sun, 13 Dec" for longer events, else nothing.
            'until' => $endsOtherDay ? ['kind' => 'date', 'date' => self::parts($endTime === '' ? $end->modify('-1 second') : $end)]
                : ($endTime !== '' ? ['kind' => 'time', 'time' => $endTime] : ['kind' => 'none']),
            'left' => ['days' => intdiv($left, 86400), 'hours' => intdiv($left % 86400, 3600), 'minutes' => intdiv($left % 3600, 60)],
        ];
    }

    /**
     * End of the event: the given end, else the end of the start day. An end before
     * the start is ignored.
     */
    private static function end(\DateTimeImmutable $start, string $date, string $time, \DateTimeZone $zone): \DateTimeImmutable
    {
        $endOfStartDay = $start->setTime(0, 0)->modify('+1 day');

        if ($date === '' && $time === '') {
            return $endOfStartDay;
        }

        $day = $date !== '' ? $date : $start->format('Y-m-d');
        $end = $time !== ''
            ? new \DateTimeImmutable($day . ' ' . $time, $zone)
            : (new \DateTimeImmutable($day, $zone))->modify('+1 day');

        return $end > $start ? $end : $endOfStartDay;
    }

    /**
     * Parts for the localized date in the template ("Sa, 12. Dez" / "Sat, Dec 12").
     *
     * @return array{weekday: int, day: int, month: int}
     */
    private static function parts(\DateTimeImmutable $date): array
    {
        return ['weekday' => (int) $date->format('w'), 'day' => (int) $date->format('j'), 'month' => (int) $date->format('n')];
    }
}
