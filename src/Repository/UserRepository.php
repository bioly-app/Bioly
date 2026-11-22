<?php

declare(strict_types=1);

namespace Bioly\Repository;

use Bioly\Support\Clock;

final class UserRepository extends Repository
{
    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return $this->one('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$username]);
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return trim($email) === '' ? null : $this->one('SELECT * FROM users WHERE email = ? COLLATE NOCASE', [trim($email)]);
    }

    /**
     * Bioly has exactly one user.
     *
     * @return array<string, mixed>|null
     */
    public function admin(): ?array
    {
        return $this->one('SELECT * FROM users ORDER BY id LIMIT 1');
    }

    public function count(): int
    {
        return (int) ($this->one('SELECT COUNT(*) AS users FROM users')['users'] ?? 0);
    }

    public function create(string $username, string $passwordHash, ?string $email, string $locale): int
    {
        $now = Clock::now();

        return $this->insert('users', [
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
            'locale' => $locale,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $values */
    public function update(int $id, array $values): void
    {
        $this->updateById('users', $id, [...$values, 'updated_at' => Clock::now()]);
    }
}
