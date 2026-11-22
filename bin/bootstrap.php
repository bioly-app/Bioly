<?php

declare(strict_types=1);

// Shared setup for command line scripts.

use Bioly\Services;

$rootPath = dirname(__DIR__);

require $rootPath . '/vendor/autoload.php';

return Services::fromRoot($rootPath);
