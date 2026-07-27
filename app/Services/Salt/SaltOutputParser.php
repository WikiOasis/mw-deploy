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

        $minionReturn = $envelope[$target] ?? (count($envelope) === 1 ? reset($envelope) : null);

        if ($minionReturn === null) {
            return new SaltResult(
                ok: false,
                target: $target,
                retcode: $saltExitCode,
                stdout: $saltStdout,
                stderr: $saltStderr,
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

        return "Could not parse salt --out=json output (exit {$exitCode}): ".$this->truncate($detail);
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
