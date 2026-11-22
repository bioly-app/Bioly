<?php

declare(strict_types=1);

// Applies pending migrations. Runs automatically when the dev container starts.

/** @var Bioly\Services $services */
$services = require __DIR__ . '/bootstrap.php';

if (!$services->databaseExists()) {
    echo "No database yet. Run the installer or the seed script.\n";
    exit(0);
}

$applied = $services->migrator()->migrate();

echo $applied === [] ? "Database is up to date.\n" : 'Applied: ' . implode(', ', $applied) . "\n";
