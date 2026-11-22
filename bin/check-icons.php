<?php

declare(strict_types=1);

// Checks the icon folders and lists problems. Usage: make check-icons

use Bioly\Icon\IconRegistry;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$registry = new IconRegistry($root . '/resources/icons', $root . '/resources/originals', sys_get_temp_dir() . '/bioly-icons-check.php');
@unlink(sys_get_temp_dir() . '/bioly-icons-check.php');

$problems = $registry->problems();

if ($problems === []) {
    echo "All icons are fine.\n";
    exit(0);
}

echo "Problems:\n";

foreach ($problems as $problem) {
    echo "  - {$problem}\n";
}

exit(1);
