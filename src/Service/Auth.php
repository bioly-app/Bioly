<?php

declare(strict_types=1);

namespace Bioly\Service;

use Bioly\Repository\LoginAttemptRepository;
use Bioly\Repository\UserRepository;
use Bioly\Support\Clock;
use Bioly\Support\Crypto;
use Bioly\Support\PasswordPolicy;
use Bioly\Support\Session;

final class Auth
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const LOCKED = 'locked';

    private const ALGORITHM = PASSWORD_ARGON2ID;

    /** @var array<string, mixed>|null|false false = not loaded yet */
    private array|false|null $user = false;

    /** @param array{max_failures: int, window_seconds: int, account_max_failures: int, account_window_seconds: int} $limits */
    public function __construct(
        private readonly UserRepository $users,
        private readonly LoginAttemptRepository $attempts,
        private readonly Session $session,
        private readonly Crypto $crypto,
        private readonly array $limits,
        private readonly int $idleSeconds,
        private readonly int $maxSeconds,
    ) {}

    public static function hash(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? self::ALGORITHM : PASSWORD_DEFAULT);
    }

    /**
     * @param string $identifier username or e-mail address
     */
    public function attempt(string $identifier, string $password, string $ip): string
    {
        $username = $identifier;

        if ($this->lockSeconds($identifier, $ip) > 0) {
            return self::LOCKED;
        }

        $ipHash = $this->crypto->hmac($ip);
        $user = $this->users->findByUsername($identifier)
            ?? (str_contains($identifier, '@') ? $this->users->findByEmail($identifier) : null);
        // Verify against a real dummy hash when the user does not exist, so timing reveals nothing.
        $valid = password_verify($password, $user['password_hash'] ?? self::hash('dummy-password'));

        if ($user === null || !$valid) {
            $this->attempts->add($ipHash, $username, false);
            $this->attempts->prune();

            return self::INVALID;
        }

        $this->attempts->clearFailures($ipHash, $username);

        if (password_needs_rehash($user['password_hash'], defined('PASSWORD_ARGON2ID') ? self::ALGORITHM : PASSWORD_DEFAULT)) {
            $this->users->update((int) $user['id'], ['password_hash' => self::hash($password)]);
        }

        $this->login((int) $user['id']);

        return self::OK;
    }

    /**
     * Seconds until the next login attempt is allowed, 0 when not locked.
     * Two separate limits: per IP address (strict) and per account from any address (loose),
     * so nobody can lock the owner out just by knowing the username.
     */
    public function lockSeconds(string $identifier, string $ip): int
    {
        $byIp = $this->attempts->failureTimesForIp($this->crypto->hmac($ip), Clock::ago($this->limits['window_seconds']));
        $byAccount = $identifier === ''
            ? []
            : $this->attempts->failureTimesForUsername($identifier, Clock::ago($this->limits['account_window_seconds']));

        return max(
            self::remaining($byIp, $this->limits['max_failures'], $this->limits['window_seconds']),
            self::remaining($byAccount, $this->limits['account_max_failures'], $this->limits['account_window_seconds']),
        );
    }

    /**
     * The lock ends when the oldest of the last $max failures leaves the window.
     *
     * @param list<string> $times newest first
     */
    private static function remaining(array $times, int $max, int $window): int
    {
        if (count($times) < $max) {
            return 0;
        }

        return max(0, (int) strtotime($times[$max - 1] . ' UTC') + $window - time());
    }

    public function lockMinutes(): int
    {
        return intdiv($this->limits['window_seconds'], 60);
    }

    public function login(int $userId): void
    {
        $this->session->regenerate();
        $this->session->set('user_id', $userId);
        $this->session->set('login_at', Clock::now());
        $this->session->set('seen_at', time());
        $this->users->update($userId, ['last_login_at' => Clock::now()]);
        $this->user = false;
    }

    public function logout(): void
    {
        $this->session->destroy();
        $this->user = null;
    }

    /** @return array<string, mixed>|null the logged in user, or null */
    public function user(): ?array
    {
        if ($this->user !== false) {
            return $this->user;
        }

        $id = $this->session->get('user_id');
        $user = is_int($id) ? $this->users->find($id) : null;
        $idle = time() - (int) $this->session->get('seen_at', 0) > $this->idleSeconds;
        $expired = time() >= $this->sessionEndsAt();
        $revoked = $user !== null && $user['sessions_valid_after'] !== null
            && (string) $this->session->get('login_at', '') < $user['sessions_valid_after'];

        if ($user === null || $idle || $expired || $revoked) {
            $this->session->remove('user_id');

            return $this->user = null;
        }

        $this->session->set('seen_at', time());

        return $this->user = $user;
    }

    /** Unix time at which this login ends at the latest (12 hours after logging in). */
    public function sessionEndsAt(): int
    {
        $loginAt = strtotime((string) $this->session->get('login_at', '') . ' UTC');

        return ($loginAt === false ? 0 : $loginAt) + $this->maxSeconds;
    }

    public function idleSeconds(): int
    {
        return $this->idleSeconds;
    }

    public function changePassword(int $userId, string $password, bool $logoutOthers = true): void
    {
        $values = ['password_hash' => self::hash($password)];

        if ($logoutOthers) {
            $values['sessions_valid_after'] = Clock::now();
        }

        $this->users->update($userId, $values);
    }

    /**
     * @param list<string> $context words the password must not be made of (username, e-mail, site name)
     * @return string|null translation key of the problem, null if fine
     */
    public static function passwordProblem(string $password, string $repeat, array $context = []): ?string
    {
        $rules = PasswordPolicy::rules($password, $context);

        if (!$rules['length']) {
            return 'validation.password_length';
        }

        if (!$rules['guessable']) {
            return 'validation.password_guessable';
        }

        return $password === $repeat ? null : 'validation.password_repeat';
    }
}
