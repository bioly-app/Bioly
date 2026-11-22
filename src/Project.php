<?php

declare(strict_types=1);

namespace Bioly;

/**
 * Addresses of the Bioly project itself.
 */
final class Project
{
    /**
     * Contact form on the Bioly website for bugs, feature wishes and ideas, linked in the backend menu.
     * Empty until the website exists: the menu entry stays hidden.
     */
    public const FEEDBACK_URL = '';

    /** Public repository on GitHub: releases for download and for the updater. */
    public const GITHUB_REPO = 'bioly-app/bioly';

    public const RELEASES_URL = 'https://github.com/' . self::GITHUB_REPO . '/releases';
}
