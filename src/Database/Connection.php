<?php

declare(strict_types=1);

namespace Bioly\Database;

use PDO;

final class Connection
{
    public static function create(string $databasePath): PDO
    {
        $pdo = new PDO('sqlite:' . $databasePath, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // SQLite keeps foreign keys off unless enabled per connection.
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 3000');

        // Only this installation may read the database (journal files follow these rights).
        // Like data/config.php: other accounts on the same server see nothing.
        if (is_file($databasePath) && (fileperms($databasePath) & 0o077) !== 0) {
            @chmod($databasePath, 0o600);
        }

        return $pdo;
    }
}
