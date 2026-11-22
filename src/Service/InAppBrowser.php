<?php

declare(strict_types=1);

namespace Bioly\Service;

/**
 * In-app browsers of social apps. The only list: the page gets it as data-in-app,
 * so multilink.js asks before any request, and the server checks again.
 */
final class InAppBrowser
{
    /** Regular expression without delimiters, valid in PHP and JavaScript (case-insensitive). */
    public const PATTERN = 'Instagram|FBAN|FBAV|FB_IAB|FBIOS|TikTok|musical_ly|Bytedance|Snapchat|LinkedInApp|Twitter|Pinterest|Line/|MicroMessenger|GSA/';

    public static function detect(string $userAgent): bool
    {
        return preg_match('~' . self::PATTERN . '~i', $userAgent) === 1;
    }
}
