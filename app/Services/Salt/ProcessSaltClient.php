<?php

declare(strict_types=1);

namespace App\Services\Salt;

use App\Services\Salt\Contracts\PendingSaltCall;
use App\Services\Salt\Contracts\SaltClient;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * The only place in the application that touches the fleet.
 *
 * Runs the local `salt` binary with Symfony Process. argv is passed as an array
 * so no shell is involved on this side; the shim command string is a single argv
 * element that Salt hands to a shell on the minion.
 */
final class ProcessSaltClient implements SaltClient
{
    public function __construct(private readonly SaltOutputParser $parser) {}

    public function run(SaltCall $call): SaltResult
    {
        return $this->start($call)->wait();
    }

    public function start(SaltCall $call): PendingSaltCall
    {
        $argv = $call->argv();

        Log::debug('mwdeploy: salt call', ['target' => $call->target, 'argv' => $argv]);

        // Only HOME is overridden; everything else is inherited.
        $process = new Process($argv, env: $this->environment());

        // Give the subprocess more room than Salt's own timeout so a Salt-side
        // timeout surfaces as a Salt error rather than as a PHP process kill.
        $process->setTimeout(
            $call->timeoutSeconds() + (int) config('mwdeploy.salt.process_timeout_slack', 60)
        );

        try {
            $process->start();
        } catch (ExceptionInterface $exception) {
            return new FailedSaltCall($call, sprintf(
                'Could not start [%s]: %s',
                (string) config('mwdeploy.salt.binary'),
                $exception->getMessage(),
            ));
        }

        return new ProcessPendingSaltCall($call, $process, $this->parser);
    }

    /**
     * Environment overrides for the subprocess.
     *
     * Just HOME, and only because the salt CLI insists on creating `~/.salt` while
     * it parses its own arguments: run as a user whose home directory is not
     * writable — www-data's /var/www, typically — it dies with a PermissionError
     * traceback and exit 64 before contacting any minion. That failure looks like
     * "the fleet is broken" and is nothing of the sort, so the portal hands over a
     * directory it owns rather than trusting the php-fpm pool's environment.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $home = trim((string) config('mwdeploy.salt.home', ''));

        if ($home === '') {
            return [];
        }

        if (! is_dir($home) && ! @mkdir($home, 0750, true) && ! is_dir($home)) {
            // Inherit the parent's HOME instead of pointing salt at somewhere that
            // does not exist: no better, but no worse either, and the log line is
            // what actually gets this fixed.
            Log::warning('mwdeploy: could not create the salt home directory; salt will use the inherited HOME.', [
                'home' => $home,
            ]);

            return [];
        }

        return ['HOME' => $home];
    }
}
