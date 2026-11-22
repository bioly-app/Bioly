<?php

declare(strict_types=1);

namespace Bioly;

use Bioly\Http\ErrorHandler;
use Bioly\Http\Middleware\InstallGuard;
use Bioly\Http\Middleware\RunAfterUpdate;
use Bioly\Http\Middleware\SecurityHeaders;
use Bioly\Http\Routes;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;

final class Kernel
{
    /** @return App<ContainerInterface|null> */
    public static function create(string $rootPath): App
    {
        $services = Services::fromRoot($rootPath);
        $app = AppFactory::create();

        Routes::register($app, $services);

        // Slim runs middleware last-added-first: error handling wraps everything.
        $app->add(new RunAfterUpdate($services));
        $app->add(new InstallGuard($services));
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->add(new SecurityHeaders());

        $errors = $app->addErrorMiddleware($services->settings['debug'], true, true);
        $handler = new ErrorHandler($services, $app->getResponseFactory());
        $errors->setDefaultErrorHandler($handler);
        $errors->setErrorHandler(HttpNotFoundException::class, $handler);
        $errors->setErrorHandler(HttpMethodNotAllowedException::class, $handler);

        return $app;
    }
}
