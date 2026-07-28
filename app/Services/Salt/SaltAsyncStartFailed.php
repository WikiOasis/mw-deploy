<?php

declare(strict_types=1);

namespace App\Services\Salt;

use RuntimeException;

/**
 * `salt --async` did not hand back a job ID — the local CLI itself never got
 * as far as scheduling anything on a minion, so there is no JID to poll for.
 * Distinct from a job that started and simply hasn't finished yet.
 */
final class SaltAsyncStartFailed extends RuntimeException {}
