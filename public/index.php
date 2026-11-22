<?php

declare(strict_types=1);

use Bioly\Kernel;

$rootPath = dirname(__DIR__);

require $rootPath . '/vendor/autoload.php';

Kernel::create($rootPath)->run();
