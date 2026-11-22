<?php

declare(strict_types=1);

namespace Bioly\Support;

use RuntimeException;

/**
 * Secrets that must never live in the database: stored in data/config.php.
 */
final class AppConfig
{
    /** @var array<mixed>|null contents of data/config.php */
    private ?array $values = null;

    public function __construct(private readonly string $file) {}

    public function exists(): bool
    {
        return is_file($this->file);
    }

    public function create(): void
    {
        if ($this->exists()) {
            return;
        }

        $values = ['key' => base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES))];
        $php = "<?php\n\n// Bioly secrets. Never share or commit this file.\nreturn " . var_export($values, true) . ";\n";

        if (file_put_contents($this->file, $php, LOCK_EX) === false) {
            throw new RuntimeException('Could not write ' . $this->file);
        }

        @chmod($this->file, 0600);
        $this->values = $values;
    }

    /** Raw 32 byte key for encryption and HMAC. */
    public function key(): string
    {
        if ($this->values === null) {
            if (!$this->exists()) {
                throw new RuntimeException('data/config.php is missing. Run the installer.');
            }

            $values = require $this->file;
            $this->values = is_array($values) ? $values : [];
        }

        $key = $this->values['key'] ?? null;

        return (is_string($key) ? base64_decode($key, true) : false) ?: throw new RuntimeException('Invalid key in data/config.php');
    }
}
