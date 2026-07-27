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

        $process = new Process($argv);

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
}
