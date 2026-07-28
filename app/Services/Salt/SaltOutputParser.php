<?php

declare(strict_types=1);

namespace App\Services\Salt;

/**
 * Unwraps two layers of JSON.
 *
 * Layer 1 is Salt's own `--out=json` envelope, keyed by minion id. With
 * `cmd.run_all` the value is a dict carrying retcode/stdout/stderr; with
 * `cmd.run` it is just the stdout string. Both shapes are handled so the
 * command module stays configurable.
 *
 * Layer 2 is the shim's result object, which is the last JSON line on stdout.
 * We scan backwards rather than assuming the whole of stdout is JSON, because a
 * subprocess the shim invoked (rsync, git, patch) may well have printed first.
 */
final class SaltOutputParser
{
    public function parse(
        string $target,
        string $saltStdout,
        string $saltStderr,
        int $saltExitCode,
        float $durationSeconds = 0.0,
    ): SaltResult {
        $envelope = $this->decodeEnvelope($saltStdout);

        if ($envelope === null) {
            return new SaltResult(
                ok: false,
                target: $target,
                retcode: $saltExitCode,
                stdout: $saltStdout,
                stderr: $saltStderr,
                error: $this->envelopeError($saltStdout, $saltStderr, $saltExitCode),
                rawSaltOutput: $saltStdout,
                durationSeconds: $durationSeconds,
            );
        }

        return $this->parseEnvelope($target, $envelope, $saltStdout, $saltExitCode, $durationSeconds);
    }

    /**
     * The minion-keyed part of parse(), split out so a job looked up later via
     * `salt-run jobs.lookup_jid` — which returns the same per-minion shape, just
     * fetched from the job cache instead of a live subprocess — can be parsed
     * with the exact same rules instead of duplicating them.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function parseEnvelope(
        string $target,
        array $envelope,
        string $rawSaltOutput,
        int $saltExitCode,
        float $durationSeconds = 0.0,
    ): SaltResult {
        $saltStdout = $rawSaltOutput;
        $minionReturn = $envelope[$target] ?? (count($envelope) === 1 ? reset($envelope) : null);

        if ($minionReturn === null) {
            return new SaltResult(
                ok: false,
                target: $target,
                retcode: $saltExitCode,
                stdout: $saltStdout,
                stderr: '',
                error: "Salt returned no result for minion [{$target}].",
                rawSaltOutput: $saltStdout,
                durationSeconds: $durationSeconds,
            );
        }

        // Salt reports an unreachable minion as a plain string in the envelope,
        // e.g. "Minion did not return. [No response]".
        if (is_string($minionReturn) && $this->looksLikeSaltFailureNotice($minionReturn)) {
            return new SaltResult(
                ok: false,
                target: $target,
                retcode: $saltExitCode,
                stdout: '',
                stderr: '',
                error: trim($minionReturn),
                rawSaltOutput: $saltStdout,
                durationSeconds: $durationSeconds,
            );
        }

        [$stdout, $stderr, $retcode] = $this->normaliseMinionReturn($minionReturn, $saltExitCode);

        $payload = $this->extractShimPayload($stdout) ?? $this->extractShimPayload($stderr);

        // The shim's own verdict wins when it gave us one; otherwise fall back to
        // the process retcode, which is what a crash before printing looks like.
        $ok = $payload !== null
            ? ($payload['ok'] ?? false) === true
            : $retcode === 0;

        $error = null;

        if (! $ok) {
            $error = is_string($payload['error'] ?? null) && $payload['error'] !== ''
                ? $payload['error']
                : $this->fallbackError($stdout, $stderr, $retcode);
        }

        return new SaltResult(
            ok: $ok,
            target: $target,
            retcode: $retcode,
            stdout: $stdout,
            stderr: $stderr,
            payload: $payload,
            error: $error,
            rawSaltOutput: $saltStdout,
            durationSeconds: $durationSeconds,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeEnvelope(string $saltStdout): ?array
    {
        $trimmed = trim($saltStdout);

        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array{0: string, 1: string, 2: int|null}
     */
    private function normaliseMinionReturn(mixed $minionReturn, int $saltExitCode): array
    {
        if (is_array($minionReturn)) {
            return [
                is_string($minionReturn['stdout'] ?? null) ? $minionReturn['stdout'] : '',
                is_string($minionReturn['stderr'] ?? null) ? $minionReturn['stderr'] : '',
                array_key_exists('retcode', $minionReturn) ? (int) $minionReturn['retcode'] : null,
            ];
        }

        // cmd.run: only stdout is available, so the salt CLI's own exit code is
        // the only signal about success.
        return [
            is_scalar($minionReturn) ? (string) $minionReturn : '',
            '',
            $saltExitCode === 0 ? 0 : $saltExitCode,
        ];
    }

    /**
     * Last line of the stream that decodes to a JSON object with an `ok` key.
     *
     * @return array<string, mixed>|null
     */
    private function extractShimPayload(string $stream): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', $stream) ?: [];

        foreach (array_reverse($lines) as $line) {
            $line = trim($line);

            if ($line === '' || ! str_starts_with($line, '{')) {
                continue;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded) && array_key_exists('ok', $decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function looksLikeSaltFailureNotice(string $value): bool
    {
        return str_contains($value, 'Minion did not return')
            || str_contains($value, 'is not available')
            || str_contains($value, 'No response');
    }

    private function envelopeError(string $stdout, string $stderr, int $exitCode): string
    {
        $detail = trim($stderr) !== '' ? trim($stderr) : trim($stdout);

        if ($detail === '') {
            return "salt exited {$exitCode} with no output.";
        }

        // Distinguish "the local CLI never ran" from "the minion misbehaved". Both
        // arrive here as unparseable output, and conflating them sends whoever is
        // holding the pager to the wrong host.
        if ($this->looksLikeLocalCliFailure($detail, $exitCode)) {
            return 'The local salt CLI refused to run (exit '.$exitCode.'), so nothing was sent to any minion: '
                .$this->truncate($detail).$this->localCliHint($detail);
        }

        // Salt's own "nobody answered at all" message: unlike "Minion did not
        // return. [No response]" (which arrives *inside* the JSON envelope,
        // keyed by minion id) this is printed instead of any JSON when the job
        // got zero returns before the CLI's --timeout expired. It reads like a
        // parse failure but it is Salt reporting an unresponsive/unreachable
        // fleet, not a shim problem.
        if (str_contains($detail, 'No return received')) {
            return 'No return received from any minion before the Salt timeout (exit '.$exitCode.'): '
                .$this->truncate($detail);
        }

        return "Could not parse salt --out=json output (exit {$exitCode}): ".$this->truncate($detail);
    }

    /**
     * Whether salt failed on *this* host, before reaching the fleet.
     *
     * Exit 64 is Salt's argument-parsing failure, and a Python traceback on stderr
     * cannot have come from a minion — the minion's output arrives inside the JSON
     * envelope, not instead of it.
     */
    private function looksLikeLocalCliFailure(string $detail, int $exitCode): bool
    {
        return $exitCode === 64
            || str_contains($detail, 'Usage: salt')
            || str_contains($detail, 'salt: error:')
            || str_contains($detail, 'Traceback (most recent call last)');
    }

    /**
     * One specific trap gets a specific answer, because it is the one everybody
     * hits: the salt CLI creates `~/.salt` while parsing its arguments, and
     * php-fpm's HOME is whatever the passwd entry says — /var/www for www-data,
     * which is usually root-owned.
     */
    private function localCliHint(string $detail): string
    {
        if (! str_contains($detail, '.salt') || ! str_contains($detail, 'Permission denied')) {
            return '';
        }

        return ' — salt could not create its own state directory under the web user'."'"
            .'s home. Point MWDEPLOY_SALT_HOME at a directory that user owns '
            .'(it defaults to storage/framework/salt), or give the user a writable HOME.';
    }

    private function fallbackError(string $stdout, string $stderr, ?int $retcode): string
    {
        $detail = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
        $prefix = $retcode === null ? 'shim failed' : "shim exited {$retcode}";

        return $detail === ''
            ? $prefix.' with no output.'
            : $prefix.': '.$this->truncate($detail);
    }

    private function truncate(string $value, int $limit = 2000): string
    {
        return strlen($value) <= $limit ? $value : substr($value, 0, $limit).'… (truncated)';
    }
}
