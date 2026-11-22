<?php

declare(strict_types=1);

namespace Bioly\Update;

use RuntimeException;

/**
 * Small HTTPS client for the update check and the download. Uses cURL where it exists,
 * otherwise PHP streams (some shared hosts have only one of them).
 */
final class HttpClient
{
    public function __construct(private readonly string $userAgent) {}

    /**
     * GET a URL and return the body. With $saveTo the body goes into that file instead
     * (for the zip), and the method returns an empty string.
     *
     * @param array<string, string> $headers
     */
    public function get(string $url, array $headers = [], ?string $saveTo = null, int $maxBytes = 50 * 1024 * 1024, int $timeout = 20): string
    {
        if (!str_starts_with($url, 'https://')) {
            throw new RuntimeException('Only https addresses are allowed.');
        }

        $lines = ['User-Agent: ' . $this->userAgent];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return function_exists('curl_init')
            ? $this->curl($url, $lines, $saveTo, $maxBytes, $timeout)
            : $this->stream($url, $lines, $saveTo, $maxBytes, $timeout);
    }

    /** @param list<string> $headers */
    private function curl(string $url, array $headers, ?string $saveTo, int $maxBytes, int $timeout): string
    {
        $body = '';
        $file = $saveTo !== null ? fopen($saveTo, 'wb') : null;

        if ($file === false) {
            throw new RuntimeException('Could not write ' . $saveTo);
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FAILONERROR => true,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, $file, $maxBytes): int {
                static $size = 0;
                $size += strlen($chunk);

                if ($size > $maxBytes) {
                    return 0;
                }

                $file !== null ? fwrite($file, $chunk) : $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if ($file !== null) {
            fclose($file);
        }

        if ($ok === false) {
            throw new RuntimeException('Download failed: ' . $error);
        }

        return $body;
    }

    /** @param list<string> $headers */
    private function stream(string $url, array $headers, ?string $saveTo, int $maxBytes, int $timeout): string
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('This server allows no downloads (neither cURL nor allow_url_fopen).');
        }

        $context = stream_context_create([
            'http' => ['header' => implode("\r\n", $headers), 'timeout' => $timeout, 'follow_location' => 1, 'max_redirects' => 5, 'ignore_errors' => false],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $in = @fopen($url, 'rb', false, $context);

        if ($in === false) {
            throw new RuntimeException('Download failed: ' . $url);
        }

        $out = $saveTo !== null ? fopen($saveTo, 'wb') : null;
        $body = '';
        $size = 0;

        try {
            while (!feof($in)) {
                $chunk = (string) fread($in, 65536);
                $size += strlen($chunk);

                if ($size > $maxBytes) {
                    throw new RuntimeException('The download is too large.');
                }

                $out !== null && $out !== false ? fwrite($out, $chunk) : $body .= $chunk;
            }
        } finally {
            fclose($in);

            if (is_resource($out)) {
                fclose($out);
            }
        }

        return $body;
    }
}
