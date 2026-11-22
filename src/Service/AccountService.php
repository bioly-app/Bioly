<?php

declare(strict_types=1);

namespace Bioly\Service;

use Bioly\I18n\Translator;
use Bioly\Repository\TokenRepository;
use Bioly\Repository\UserRepository;
use Bioly\Support\Clock;
use Bioly\Support\Crypto;
use RuntimeException;

/**
 * E-mail confirmation codes and password resets (by mail or by FTP file).
 */
final class AccountService
{
    private const CODE_LIFETIME = 15 * 60;
    private const CODE_MAX_ATTEMPTS = 5;
    private const RESEND_WAIT = 60;
    private const MAIL_RESET_LIFETIME = 30 * 60;
    private const MAIL_RESET_WAIT = 60;
    private const MAIL_RESET_PER_HOUR = 5;
    private const FTP_RESET_LIFETIME = 15 * 60;

    public function __construct(
        private readonly UserRepository $users,
        private readonly TokenRepository $tokens,
        private readonly MailService $mail,
        private readonly Translator $translator,
        private readonly string $dataPath,
    ) {}

    /**
     * Sends a 6 digit code to the user's address.
     *
     * @param array<string, mixed> $user
     * @return string|null translation key of the problem, null on success
     */
    public function sendCode(array $user): ?string
    {
        if (($user['email'] ?? '') === '') {
            return 'account.code.no_email';
        }

        $open = $this->tokens->open((int) $user['id'], 'email_verify');

        if ($open !== null && strtotime($open['created_at'] . ' UTC') > time() - self::RESEND_WAIT) {
            return 'account.code.wait';
        }

        $code = sprintf('%06d', random_int(0, 999999));
        $this->tokens->create((int) $user['id'], 'email_verify', Crypto::hashToken($code), self::CODE_LIFETIME);

        try {
            $this->mail->send(
                $user['email'],
                $this->translator->translate('mail.code.subject'),
                $this->translator->translate('mail.code.body', ['code' => $code]),
            );
        } catch (\Throwable $exception) {
            $this->tokens->deleteFor((int) $user['id'], 'email_verify');
            error_log('Bioly mail: ' . $exception->getMessage());

            return 'account.code.send_failed';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $user
     * @return string|null translation key of the problem, null when the code was right
     */
    public function verifyCode(array $user, string $code): ?string
    {
        $token = $this->tokens->open((int) $user['id'], 'email_verify');

        if ($token === null || $token['attempts'] >= self::CODE_MAX_ATTEMPTS) {
            return 'account.code.expired';
        }

        if (!hash_equals($token['token_hash'], Crypto::hashToken(preg_replace('/\D/', '', $code) ?? ''))) {
            $this->tokens->countAttempt((int) $token['id']);

            return 'account.code.wrong';
        }

        $this->tokens->markUsed((int) $token['id']);
        $now = Clock::now();
        $this->users->update((int) $user['id'], ['email_verified_at' => $now]);
        $this->mail->markVerified($now);

        return null;
    }

    /**
     * Sends a reset link to the address stored in the account, never to an address typed in.
     * Returns 0 when the request was handled (also when no address is stored, so nothing
     * about the account is revealed), otherwise the seconds to wait before the next try.
     */
    public function requestMailReset(string $resetUrl): int
    {
        $user = $this->users->admin();

        if ($user === null || !$this->mail->isVerified() || ($user['email'] ?? '') === '') {
            return 0;
        }

        $userId = (int) $user['id'];
        $last = $this->tokens->lastCreated($userId, 'password_mail');
        $wait = $last !== null ? (int) strtotime($last . ' UTC') + self::MAIL_RESET_WAIT - time() : 0;

        if ($wait > 0) {
            return $wait;
        }

        if ($this->tokens->countSince($userId, 'password_mail', Clock::ago(3600)) >= self::MAIL_RESET_PER_HOUR) {
            return 3600;
        }

        $token = bin2hex(random_bytes(32));
        // A new link makes older links invalid.
        $this->tokens->createKeepingHistory($userId, 'password_mail', Crypto::hashToken($token), self::MAIL_RESET_LIFETIME);

        try {
            $this->mail->send(
                $user['email'],
                $this->translator->translate('mail.reset.subject'),
                $this->translator->translate('mail.reset.body', ['link' => $resetUrl . '?token=' . $token]),
            );
        } catch (\Throwable $exception) {
            error_log('Bioly mail: ' . $exception->getMessage());
        }

        return 0;
    }

    /** @return int|null user id when the token is valid; the token is used up */
    public function consumeMailReset(string $token): ?int
    {
        $row = $this->tokens->findOpenByHash('password_mail', Crypto::hashToken($token));

        if ($row === null) {
            return null;
        }

        $this->tokens->markUsed((int) $row['id']);

        return (int) $row['user_id'];
    }

    /** Creates a fresh random file name the user has to create in data/. */
    public function startFtpReset(): string
    {
        $user = $this->users->admin() ?? throw new RuntimeException('No user.');
        $name = 'reset_' . bin2hex(random_bytes(6)) . '.txt';
        $this->tokens->create((int) $user['id'], 'password_ftp', Crypto::hashToken($name), self::FTP_RESET_LIFETIME);

        return $name;
    }

    /** @return int|null user id when the file exists and the name is still valid */
    public function checkFtpReset(string $name): ?int
    {
        if (preg_match('/^reset_[a-f0-9]{12}\.txt$/', $name) !== 1) {
            return null;
        }

        $row = $this->tokens->findOpenByHash('password_ftp', Crypto::hashToken($name));
        $file = $this->dataPath . '/' . $name;

        if ($row === null || !is_file($file)) {
            return null;
        }

        @unlink($file);
        $this->tokens->markUsed((int) $row['id']);

        return (int) $row['user_id'];
    }
}
