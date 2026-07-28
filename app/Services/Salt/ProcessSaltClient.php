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

    public function startAsync(SaltCall $call): string
    {
        $process = new Process($call->asyncArgv(), env: $this->environment());

        // Only waiting for Salt to accept the job and hand back a JID, not for any
        // minion to finish — a few seconds at most, regardless of how long the
        // call itself is allowed to run once scheduled.
        $process->setTimeout((int) config('mwdeploy.salt.async_start_timeout', 30));

        try {
            $process->run();
        } catch (ExceptionInterface $exception) {
            throw new SaltAsyncStartFailed(sprintf(
                'Could not start [%s] asynchronously: %s',
                (string) config('mwdeploy.salt.binary'),
                $exception->getMessage(),
            ));
        }

        $decoded = json_decode(trim($process->getOutput()), true);
        $jid = is_array($decoded) ? ($decoded['jid'] ?? null) : null;

        if (! is_string($jid) || $jid === '') {
            $detail = trim($process->getErrorOutput()) !== ''
                ? trim($process->getErrorOutput())
                : trim($process->getOutput());

            throw new SaltAsyncStartFailed(sprintf(
                'salt --async did not hand back a job ID (exit %d): %s',
                $process->getExitCode() ?? -1,
                $detail === '' ? '(no output)' : $detail,
            ));
        }

        return $jid;
    }

    public function lookupJid(string $jid, string $target): ?SaltResult
    {
        $process = new Process(
            [(string) config('mwdeploy.salt.run_binary'), '--out=json', 'jobs.lookup_jid', $jid],
            env: $this->environment(),
        );

        // A local master-side cache read — same order of magnitude as
        // startAsync(), independent of how long the job itself has been running.
        $process->setTimeout((int) config('mwdeploy.salt.async_poll_timeout', 30));

        try {
            $process->run();
        } catch (ExceptionInterface $exception) {
            return new SaltResult(
                ok: false,
                target: $target,
                retcode: null,
                error: sprintf('Could not run [%s] jobs.lookup_jid: %s', (string) config('mwdeploy.salt.run_binary'), $exception->getMessage()),
            );
        }

        $decoded = json_decode(trim($process->getOutput()), true);
        $envelope = is_array($decoded) ? ($decoded[$jid] ?? null) : null;

        // No minion has reported back into the job cache yet — jobs.lookup_jid
        // answers an unfinished job with {}, not an error. The caller polls again.
        if (! is_array($envelope) || $envelope === []) {
            return null;
        }

        return $this->parser->parseEnvelope(
            target: $target,
            envelope: $envelope,
            rawSaltOutput: $process->getOutput(),
            saltExitCode: $process->getExitCode() ?? 0,
        );
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
