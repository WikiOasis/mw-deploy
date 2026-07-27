<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use RuntimeException;

/**
 * The tree could not be read. Never partially: a half-read farm would produce an
 * import plan full of phantom removals, so a failed scan is surfaced as an error
 * rather than as an empty inventory.
 */
final class ScanFailed extends RuntimeException {}
