<?php

declare(strict_types=1);

namespace Bioly\Repository;

use Bioly\Support\Clock;

final class LoginAttemptRepository extends Repository
{
    public function add(string $ipHash, string $username, bool $success): void
    {
        $this->insert('login_attempts', [
            'ip_hash' => $ipHash,
            'username' => mb_strtolower($username),
            'success' => $success ? 1 : 0,
            'attempted_at' => Clock::now(),
        ]);
    }

    /** @return list<string> times of failures from this address since $since, newest first */
    public function failureTimesForIp(string $ipHash, string $since): array
    {
        return $this->times('SELECT attempted_at FROM login_attempts
             WHERE success = 0 AND attempted_at >= ? AND ip_hash = ?
             ORDER BY attempted_at DESC', [$since, $ipHash]);
    }

    /** @return list<string> times of failures for this username since $since, newest first */
    public function failureTimesForUsername(string $username, string $since): array
    {
        return $this->times('SELECT attempted_at FROM login_attempts
             WHERE success = 0 AND attempted_at >= ? AND username = ?
             ORDER BY attempted_at DESC', [$since, mb_strtolower($username)]);
    }

    public function clearFailures(string $ipHash, string $username): void
    {
        $this->run('DELETE FROM login_attempts WHERE success = 0 AND (ip_hash = ? OR username = ?)', [$ipHash, mb_strtolower($username)]);
    }

    public function prune(): void
    {
        $this->run('DELETE FROM login_attempts WHERE attempted_at < ?', [Clock::ago(24 * 3600)]);
    }

    /**
     * @param list<string> $params
     * @return list<string>
     */
    private function times(string $sql, array $params): array
    {
        return array_map(static fn(array $row): string => (string) $row['attempted_at'], $this->all($sql, $params));
    }
}
