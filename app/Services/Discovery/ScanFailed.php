<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use RuntimeException;

/**
 * The tree could not be read. Never partially: a half-read farm would produce an
 * import plan full of phantom removals, so a failed scan is surfaced as an error
 * rather than as an empty inventory.
 *
 * The hint matters as much as the message. A scan can fail on the portal host (the
 * salt CLI itself) or on the staging minion (the shim), and those are two different
 * machines to go and look at.
 */
final class ScanFailed extends RuntimeException
{
    public function __construct(string $message, private readonly string $hint = '')
    {
        parent::__construct($message);
    }

    public function hint(): ?string
    {
        return $this->hint === '' ? null : $this->hint;
    }
}
