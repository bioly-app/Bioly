<?php

declare(strict_types=1);

namespace Bioly\Http\Controller\Admin;

use Bioly\Http\Controller\Controller;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Credits: the open source work Bioly is built on, with licenses and links.
 */
final class CreditsController extends Controller
{
    /** @var list<array{name: string, url: string, license: string, use: string}> */
    private const PARTS = [
        ['name' => 'Lucide', 'url' => 'https://lucide.dev', 'license' => 'ISC', 'use' => 'credits.use.lucide'],
        ['name' => 'Feather', 'url' => 'https://feathericons.com', 'license' => 'MIT', 'use' => 'credits.use.feather'],
        ['name' => 'Simple Icons', 'url' => 'https://simpleicons.org', 'license' => 'CC0-1.0', 'use' => 'credits.use.simple_icons'],
        ['name' => 'SVG Logos (Gil Barbara)', 'url' => 'https://github.com/gilbarbara/logos', 'license' => 'CC0-1.0', 'use' => 'credits.use.svg_logos'],
        ['name' => 'Slim Framework', 'url' => 'https://www.slimframework.com', 'license' => 'MIT', 'use' => 'credits.use.slim'],
        ['name' => 'Twig', 'url' => 'https://twig.symfony.com', 'license' => 'BSD-3-Clause', 'use' => 'credits.use.twig'],
        ['name' => 'Symfony Mailer', 'url' => 'https://symfony.com/doc/current/mailer.html', 'license' => 'MIT', 'use' => 'credits.use.mailer'],
        ['name' => 'FastRoute', 'url' => 'https://github.com/nikic/FastRoute', 'license' => 'BSD-3-Clause', 'use' => 'credits.use.fastroute'],
        ['name' => 'SQLite', 'url' => 'https://sqlite.org', 'license' => 'Public Domain', 'use' => 'credits.use.sqlite'],
    ];

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($response, 'backend/credits.twig', ['parts' => self::PARTS]);
    }
}
