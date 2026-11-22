<?php

declare(strict_types=1);

namespace Bioly\Support;

use RuntimeException;

final class Crypto
{
    public function __construct(private readonly AppConfig $config) {}

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->config->key()));
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);

        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Invalid encrypted value.');
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->config->key(),
        );

        return $plain === false ? throw new RuntimeException('Could not decrypt value.') : $plain;
    }

    /** Keyed hash, e.g. to store IP addresses without keeping them. */
    public function hmac(string $value): string
    {
        return hash_hmac('sha256', $value, $this->config->key());
    }

    /** Fast hash for random tokens (they have enough entropy already). */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
