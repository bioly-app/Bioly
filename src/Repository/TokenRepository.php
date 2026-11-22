<?php

declare(strict_types=1);

namespace Bioly\Repository;

use Bioly\Support\Clock;

final class TokenRepository extends Repository
{
    public function create(int $userId, string $purpose, string $tokenHash, int $lifetimeSeconds): int
    {
        // Only one open token per purpose and user.
        $this->run('DELETE FROM tokens WHERE user_id = ? AND purpose = ?', [$userId, $purpose]);

        return $this->insert('tokens', [
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => $tokenHash,
            'expires_at' => Clock::in($lifetimeSeconds),
            'created_at' => Clock::now(),
        ]);
    }

    /**
     * Like create(), but older tokens of this purpose are only marked as used, not deleted,
     * so the history is there for rate limits (see countSince()).
     */
    public function createKeepingHistory(int $userId, string $purpose, string $tokenHash, int $lifetimeSeconds): int
    {
        $now = Clock::now();
        $this->run('UPDATE tokens SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [$now, $userId, $purpose]);
        $this->run('DELETE FROM tokens WHERE purpose = ? AND created_at < ?', [$purpose, Clock::ago(24 * 3600)]);

        return $this->insert('tokens', [
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => $tokenHash,
            'expires_at' => Clock::in($lifetimeSeconds),
            'created_at' => $now,
        ]);
    }

    public function countSince(int $userId, string $purpose, string $since): int
    {
        $row = $this->one('SELECT COUNT(*) AS n FROM tokens WHERE user_id = ? AND purpose = ? AND created_at >= ?', [$userId, $purpose, $since]);

        return (int) ($row['n'] ?? 0);
    }

    public function lastCreated(int $userId, string $purpose): ?string
    {
        $row = $this->one('SELECT MAX(created_at) AS at FROM tokens WHERE user_id = ? AND purpose = ?', [$userId, $purpose]);

        return isset($row['at']) ? (string) $row['at'] : null;
    }

    /** @return array<string, mixed>|null the open, unexpired token of this user */
    public function open(int $userId, string $purpose): ?array
    {
        return $this->one(
            'SELECT * FROM tokens WHERE user_id = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?',
            [$userId, $purpose, Clock::now()],
        );
    }

    /** @return array<string, mixed>|null */
    public function findOpenByHash(string $purpose, string $tokenHash): ?array
    {
        return $this->one(
            'SELECT * FROM tokens WHERE purpose = ? AND token_hash = ? AND used_at IS NULL AND expires_at > ?',
            [$purpose, $tokenHash, Clock::now()],
        );
    }

    public function countAttempt(int $id): void
    {
        $this->run('UPDATE tokens SET attempts = attempts + 1 WHERE id = ?', [$id]);
    }

    public function markUsed(int $id): void
    {
        $this->run('UPDATE tokens SET used_at = ? WHERE id = ?', [Clock::now(), $id]);
    }

    public function deleteFor(int $userId, string $purpose): void
    {
        $this->run('DELETE FROM tokens WHERE user_id = ? AND purpose = ?', [$userId, $purpose]);
    }
}
