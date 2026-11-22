<?php

declare(strict_types=1);

namespace Bioly\Service;

use Bioly\Repository\RateHitRepository;
use Bioly\Support\Clock;
use Bioly\Support\Crypto;

/**
 * A simple sliding window per IP address: at most $limit hits in $window seconds.
 * The address is stored only as an HMAC.
 */
final class RateLimiter
{
    /** Loading the links of a multilink: enough for every real visitor, slows down bots. */
    public const MULTILINK = ['bucket' => 'multilink', 'limit' => 20, 'window' => 60];

    public function __construct(private readonly RateHitRepository $hits, private readonly Crypto $crypto) {}

    /** @param array{bucket: string, limit: int, window: int} $rule */
    public function allow(array $rule, string $ip): bool
    {
        // Everything older than the longest window is no longer needed.
        $this->hits->prune(Clock::ago($rule['window']));

        $key = $this->crypto->hmac($ip);

        if ($this->hits->count($rule['bucket'], $key, Clock::ago($rule['window'])) >= $rule['limit']) {
            return false;
        }

        $this->hits->add($rule['bucket'], $key);

        return true;
    }
}
