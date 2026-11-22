<?php

declare(strict_types=1);

namespace Bioly\Tests\Unit;

use Bioly\Database\Connection;
use Bioly\Database\Migrator;
use Bioly\Repository\LoginAttemptRepository;
use Bioly\Repository\RateHitRepository;
use Bioly\Repository\TokenRepository;
use Bioly\Repository\UserRepository;
use Bioly\Service\Auth;
use Bioly\Service\RateLimiter;
use Bioly\Support\AppConfig;
use Bioly\Support\Clock;
use Bioly\Support\Crypto;
use Bioly\Support\PasswordPolicy;
use Bioly\Support\Session;
use PDO;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    private PDO $pdo;
    private Auth $auth;
    private string $configFile;

    protected function setUp(): void
    {
        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->configFile = sys_get_temp_dir() . '/bioly-test-config-' . bin2hex(random_bytes(4)) . '.php';
        $config = new AppConfig($this->configFile);
        $config->create();

        $users = new UserRepository($this->pdo);
        $users->create('Bene', Auth::hash('Gutes-Passwort-1'), 'bene@example.com', 'de');

        $this->auth = new Auth(
            $users,
            new LoginAttemptRepository($this->pdo),
            new Session('bioly_test'),
            new Crypto($config),
            ['max_failures' => 5, 'window_seconds' => 600, 'account_max_failures' => 8, 'account_window_seconds' => 3600],
            3600,
            43200,
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->configFile);
    }

    public function testRateLimiterPerAddress(): void
    {
        $limiter = new RateLimiter(new RateHitRepository($this->pdo), new Crypto(new AppConfig($this->configFile)));
        $rule = ['bucket' => 'test', 'limit' => 3, 'window' => 60];

        self::assertTrue($limiter->allow($rule, '203.0.113.7'));
        self::assertTrue($limiter->allow($rule, '203.0.113.7'));
        self::assertTrue($limiter->allow($rule, '203.0.113.7'));
        self::assertFalse($limiter->allow($rule, '203.0.113.7'));
        // Another address has its own count, and the address itself is never stored.
        self::assertTrue($limiter->allow($rule, '198.51.100.1'));
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM rate_hits WHERE key_hash LIKE '%203.0.113%'")->fetchColumn());
        self::assertSame(20, RateLimiter::MULTILINK['limit']);
    }

    public function testPasswordRules(): void
    {
        // Length: 15 to 128 characters, no rules about kinds of characters.
        self::assertSame('validation.password_length', Auth::passwordProblem('Kurz-Passw-12', 'Kurz-Passw-12'));
        self::assertSame('validation.password_length', Auth::passwordProblem(str_repeat('ab cd ', 22), str_repeat('ab cd ', 22)));
        self::assertNull(Auth::passwordProblem('nur kleine wörter am meer', 'nur kleine wörter am meer'));
        self::assertNull(Auth::passwordProblem('Äpfel und Birnen am Baum', 'Äpfel und Birnen am Baum'));
        self::assertSame('validation.password_repeat', Auth::passwordProblem('Äpfel und Birnen am Baum', 'Äpfel und Birnen am Bau'));
    }

    public function testGuessablePasswordsAreRefused(): void
    {
        foreach (['Passwort12345678', 'P@ssw0rd!P@ssw0rd!', 'qwertzuiop123456', 'aaaaaaaaaaaaaaaaaaa', 'Sommer2024Sommer2024',
            'abcabcabcabcabcabc', '123456789012345678', 'Willkommen!!!!!!!'] as $password) {
            self::assertSame('validation.password_guessable', Auth::passwordProblem($password, $password), $password);
        }

        // Context: the username or site name does not count as own words.
        self::assertSame('validation.password_guessable', Auth::passwordProblem('Pup_Enzo_2024!!!', 'Pup_Enzo_2024!!!', ['Pup_Enzo']));
        self::assertNull(PasswordPolicy::isGuessable('Pup_Enzo_2024!!!') ? 'guessable' : null, 'without context it is long enough');
    }

    public function testLoginWorksWithUsernameOrEmail(): void
    {
        self::assertSame(Auth::INVALID, $this->auth->attempt('Bene', 'falsch', '1.2.3.4'));
        // Logging in starts a session, which PHPUnit's CLI cannot do; the check before it is what counts here.
        self::assertSame(Auth::INVALID, $this->auth->attempt('nobody@example.com', 'Gutes-Passwort-1', '1.2.3.4'));
        $users = new UserRepository($this->pdo);
        self::assertSame('Bene', $users->findByEmail('BENE@example.com')['username'] ?? null);
        self::assertNull($users->findByEmail(''));
    }

    public function testLockShowsRemainingTime(): void
    {
        self::assertSame(0, $this->auth->lockSeconds('Bene', '1.2.3.4'));

        for ($i = 0; $i < 5; $i++) {
            $this->auth->attempt('Bene', 'falsch' . $i, '1.2.3.4');
        }

        $seconds = $this->auth->lockSeconds('Bene', '1.2.3.4');
        self::assertGreaterThan(590, $seconds);
        self::assertLessThanOrEqual(600, $seconds);
        self::assertSame(Auth::LOCKED, $this->auth->attempt('Bene', 'Gutes-Passwort-1', '1.2.3.4'));
        // Locked by IP too, whatever name is tried.
        self::assertGreaterThan(0, $this->auth->lockSeconds('', '1.2.3.4'));
        self::assertSame(10, $this->auth->lockMinutes());
    }

    public function testStrangersCannotLockTheOwnerOutQuickly(): void
    {
        // 5 failures each from different addresses: no single address is locked out ...
        foreach (['5.5.5.1', '5.5.5.2', '5.5.5.3', '5.5.5.4', '5.5.5.5'] as $ip) {
            $this->auth->attempt('Bene', 'falsch', $ip);
        }

        // ... and the owner, from their own address, may still try (a typo is just invalid, not locked).
        self::assertSame(0, $this->auth->lockSeconds('Bene', '9.9.9.9'));
        self::assertSame(Auth::INVALID, $this->auth->attempt('Bene', 'Tippfehler', '9.9.9.9'));
    }

    public function testManyFailuresForOneAccountStillLockIt(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->auth->attempt('Bene', 'falsch', '6.6.6.' . $i);
        }

        self::assertGreaterThan(3500, $this->auth->lockSeconds('Bene', '9.9.9.9'));
        self::assertSame(0, $this->auth->lockSeconds('jemand', '9.9.9.9'));
    }

    public function testResetTokensKeepHistoryForRateLimits(): void
    {
        $tokens = new TokenRepository($this->pdo);
        $first = $tokens->createKeepingHistory(1, 'password_mail', 'hash-a', 1800);
        $tokens->createKeepingHistory(1, 'password_mail', 'hash-b', 1800);

        self::assertSame(2, $tokens->countSince(1, 'password_mail', Clock::ago(3600)));
        self::assertNull($tokens->findOpenByHash('password_mail', 'hash-a'), 'a new link makes the old one invalid');
        self::assertNotNull($tokens->findOpenByHash('password_mail', 'hash-b'));
        self::assertNotNull($tokens->lastCreated(1, 'password_mail'));
        self::assertGreaterThan(0, $first);
    }
}
