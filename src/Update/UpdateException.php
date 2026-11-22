<?php

declare(strict_types=1);

namespace Bioly\Update;

use RuntimeException;

/** An update problem the owner should read: the message is a translation key. */
final class UpdateException extends RuntimeException {}
