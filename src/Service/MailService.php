<?php

declare(strict_types=1);

namespace Bioly\Service;

use Bioly\Repository\SettingsRepository;
use Bioly\Support\Crypto;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class MailService
{
    public const ENCRYPTIONS = ['starttls', 'ssl', 'none'];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Crypto $crypto,
    ) {}

    public function isConfigured(): bool
    {
        return ($this->settings->getArray('smtp')['host'] ?? '') !== '';
    }

    public function isVerified(): bool
    {
        return $this->isConfigured() && !empty($this->settings->getArray('smtp')['verified_at']);
    }

    /**
     * Stores SMTP data. An empty password keeps the stored one. Resets the verification.
     *
     * @param array<string, string> $input form fields smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, smtp_from
     * @return array<string, string> field => translation key
     */
    public function save(array $input): array
    {
        $errors = [];
        $host = trim($input['smtp_host'] ?? '');
        $port = (int) ($input['smtp_port'] ?? 0);
        $from = trim($input['smtp_from'] ?? '');
        $encryption = in_array($input['smtp_encryption'] ?? '', self::ENCRYPTIONS, true) ? $input['smtp_encryption'] : 'starttls';

        if ($host === '' || preg_match('/^[a-zA-Z0-9.-]+$/', $host) !== 1) {
            $errors['smtp_host'] = 'validation.host';
        }

        if ($port < 1 || $port > 65535) {
            $errors['smtp_port'] = 'validation.port';
        }

        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            $errors['smtp_from'] = 'validation.email';
        }

        if ($errors !== []) {
            return $errors;
        }

        $current = $this->settings->getArray('smtp');
        $password = (string) ($input['smtp_password'] ?? '');

        $this->settings->set('smtp', [
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'username' => trim($input['smtp_username'] ?? ''),
            'password' => $password !== '' ? $this->crypto->encrypt($password) : ($current['password'] ?? ''),
            'from' => $from,
            'verified_at' => null,
        ]);

        return [];
    }

    public function markVerified(?string $at): void
    {
        $this->settings->merge('smtp', ['verified_at' => $at]);
    }

    /** @return array<string, mixed> stored SMTP data without the password */
    public function publicSettings(): array
    {
        $smtp = $this->settings->getArray('smtp');
        $smtp['has_password'] = ($smtp['password'] ?? '') !== '';
        unset($smtp['password']);

        return $smtp + ['host' => '', 'port' => 587, 'encryption' => 'starttls', 'username' => '', 'from' => ''];
    }

    /** Throws on failure; the caller shows a friendly message. */
    public function send(string $to, string $subject, string $text): void
    {
        $smtp = $this->settings->getArray('smtp');
        $mailer = new Mailer(Transport::fromDsn($this->dsn($smtp)));

        $mailer->send(
            (new Email())
                ->from(new Address($smtp['from'], 'Bioly'))
                ->to($to)
                ->subject($subject)
                ->text($text),
        );
    }

    /** @param array<string, mixed> $smtp */
    private function dsn(array $smtp): string
    {
        $scheme = ($smtp['encryption'] ?? '') === 'ssl' ? 'smtps' : 'smtp';
        $password = ($smtp['password'] ?? '') !== '' ? $this->crypto->decrypt($smtp['password']) : '';
        $auth = ($smtp['username'] ?? '') !== ''
            ? rawurlencode($smtp['username']) . ':' . rawurlencode($password) . '@'
            : '';
        $query = ($smtp['encryption'] ?? '') === 'none' ? '?auto_tls=false' : '';

        return sprintf('%s://%s%s:%d%s', $scheme, $auth, $smtp['host'], (int) $smtp['port'], $query);
    }
}
