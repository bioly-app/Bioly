<?php

declare(strict_types=1);

namespace Bioly\Repository;

use Bioly\Support\Clock;

final class RateHitRepository extends Repository
{
    public function add(string $bucket, string $keyHash): void
    {
        $this->insert('rate_hits', ['bucket' => $bucket, 'key_hash' => $keyHash, 'hit_at' => Clock::now()]);
    }

    public function count(string $bucket, string $keyHash, string $since): int
    {
        $row = $this->one('SELECT COUNT(*) AS hits FROM rate_hits WHERE bucket = ? AND key_hash = ? AND hit_at >= ?', [$bucket, $keyHash, $since]);

        return (int) ($row['hits'] ?? 0);
    }

    public function prune(string $before): void
    {
        $this->run('DELETE FROM rate_hits WHERE hit_at < ?', [$before]);
    }
}
