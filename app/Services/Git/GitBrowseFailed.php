<?php

declare(strict_types=1);

namespace App\Services\Git;

use RuntimeException;

/**
 * A resolve/tree/blob call could not be answered — an unknown ref, a path that
 * does not exist at that commit, or the underlying git/Salt call failing.
 * Surfaced to the API as a 404 rather than an empty listing, since "nothing
 * here" and "could not read this" are different facts for the browser UI.
 */
final class GitBrowseFailed extends RuntimeException {}
