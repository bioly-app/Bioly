<?php

declare(strict_types=1);

namespace Bioly;

/**
 * Addresses of the Bioly project itself.
 */
final class Project
{
    /** Public repository on GitHub: releases for download and for the updater. */
    public const GITHUB_REPO = 'bioly-app/bioly';

    /** Bugs, feature wishes and ideas (GitHub issues), linked in the backend menu. */
    public const FEEDBACK_URL = 'https://github.com/' . self::GITHUB_REPO . '/issues/new/choose';

    public const RELEASES_URL = 'https://github.com/' . self::GITHUB_REPO . '/releases';
}
