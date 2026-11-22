<?php

declare(strict_types=1);

namespace Bioly\Module;

use Bioly\Icon\IconRegistry;
use Bioly\Service\MediaService;

/**
 * Validates block data against a module's fields(). Unknown keys are dropped.
 */
final class Validator
{
    private const URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** Longest address Bioly stores. */
    public const MAX_URL = 2000;

    public function __construct(private readonly IconRegistry $icons) {}

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, mixed>, errors: array<string, string>} errors: field => translation key
     */
    public function validate(Module $module, array $input): array
    {
        $data = [];
        $errors = [];

        foreach ($module->fields() as $field) {
            $name = $field['name'];
            $raw = $input[$name] ?? null;

            if ($field['type'] === 'toggle') {
                $data[$name] = filter_var($raw, FILTER_VALIDATE_BOOLEAN);
                continue;
            }

            $value = is_scalar($raw) ? trim((string) $raw) : '';
            $data[$name] = $value;

            if ($value === '') {
                if (!empty($field['required'])) {
                    $errors[$name] = 'validation.required';
                }
                continue;
            }

            // Image lists are checked as a whole and stored in one clean, re-encoded form.
            if ($field['type'] === 'images') {
                $images = self::images($value);
                if ($images === null) {
                    $errors[$name] = 'validation.images';
                } else {
                    $data[$name] = $images;
                }
                continue;
            }

            $error = match ($field['type']) {
                'url' => self::isUrl($value) ? null : 'validation.url',
                'icon' => $this->icons->has($value) ? null : 'validation.icon',
                'select' => in_array($value, $field['options'] ?? [], true) ? null : 'validation.option',
                'date' => self::isDate($value) ? null : 'validation.date',
                'time' => self::isTime($value) ? null : 'validation.time',
                default => null,
            };

            if ($error === null && isset($field['max']) && mb_strlen($value) > $field['max']) {
                $error = 'validation.max';
            }

            if ($error !== null) {
                $errors[$name] = $error;
            }
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /** At most this many images in one image module. */
    public const MAX_IMAGES = 12;

    /**
     * Checks a JSON list of images [{file, url}] and returns it re-encoded, or null when invalid.
     * Files must be library images; whether they still exist is checked when rendering.
     */
    public static function images(string $json): ?string
    {
        $list = json_decode($json, true);

        if (!is_array($list) || !array_is_list($list) || $list === [] || count($list) > self::MAX_IMAGES) {
            return null;
        }

        $clean = [];

        foreach ($list as $image) {
            $file = is_array($image) && is_string($image['file'] ?? null) ? $image['file'] : '';
            $url = is_array($image) && is_string($image['url'] ?? null) ? trim($image['url']) : '';

            if (!MediaService::validName($file, ['image', 'avatar']) || ($url !== '' && !self::isUrl($url))) {
                return null;
            }

            $clean[] = ['file' => $file, 'url' => $url];
        }

        return (string) json_encode($clean, JSON_UNESCAPED_SLASHES);
    }

    /** A link a visitor may open: http, https, mailto or tel, at most MAX_URL long. */
    public static function isUrl(string $value): bool
    {
        if (strlen($value) > self::MAX_URL) {
            return false;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        if (!in_array($scheme, self::URL_SCHEMES, true)) {
            return false;
        }

        return in_array($scheme, ['mailto', 'tel'], true)
            ? strlen($value) > strlen($scheme) + 1
            : filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /** A calendar date as the browser's date field sends it: YYYY-MM-DD. */
    public static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /** A time of day as the browser's time field sends it: HH:MM, 24 hours. */
    public static function isTime(string $value): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    /** Only a web page (http or https), e.g. for an external imprint. */
    public static function isWebUrl(string $value): bool
    {
        return preg_match('#^https?://#i', $value) === 1 && self::isUrl($value);
    }
}
