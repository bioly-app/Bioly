<?php

declare(strict_types=1);

namespace Bioly\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The address of the site, e.g. https://example.org, for links that leave the site (e-mails).
 * It is stored once and never taken from a normal request: the Host header comes from the
 * visitor and could point a reset link to a foreign server.
 */
final class SiteUrl
{
    /** "https://host[:port]" without path, or null when the value is not such an address. */
    public static function normalize(string $value): ?string
    {
        $parts = parse_url(trim($value));

        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');
        $rest = array_diff_key($parts, array_flip(['scheme', 'host', 'port', 'path']));

        if ($rest !== [] || trim($parts['path'] ?? '', '/') !== '' || !self::validHost($host)) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return strtolower($parts['scheme']) . '://' . $host . $port;
    }

    /** The address the request was made to, or null when its host is not a valid name. */
    public static function fromRequest(ServerRequestInterface $request): ?string
    {
        $uri = $request->getUri();
        $port = $uri->getPort();

        return self::normalize($uri->getScheme() . '://' . $uri->getHost() . ($port !== null ? ':' . $port : ''));
    }

    private static function validHost(string $host): bool
    {
        return $host !== ''
            && strlen($host) <= 253
            && (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
                || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false);
    }
}
