<?php

declare(strict_types=1);

namespace App\Services\Salt;

use App\Services\Salt\Contracts\PendingSaltCall;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ProcessPendingSaltCall implements PendingSaltCall
{
    private ?SaltResult $result = null;

    private readonly float $startedAt;

    public function __construct(
        private readonly SaltCall $saltCall,
        private readonly Process $process,
        private readonly SaltOutputParser $parser,
    ) {
        $this->startedAt = microtime(true);
    }

    public function call(): SaltCall
    {
        return $this->saltCall;
    }

    public function isFinished(): bool
    {
        if ($this->result !== null) {
            return true;
        }

        try {
            return ! $this->process->isRunning();
        } catch (ProcessTimedOutException $exception) {
            $this->result = $this->timedOut($exception);

            return true;
        }
    }

    public function wait(): SaltResult
    {
        if ($this->result !== null) {
            return $this->result;
        }

        try {
            $exitCode = $this->process->wait();
        } catch (ProcessTimedOutException $exception) {
            return $this->result = $this->timedOut($exception);
        }

        return $this->result = $this->parser->parse(
            target: $this->saltCall->target,
            saltStdout: $this->process->getOutput(),
            saltStderr: $this->process->getErrorOutput(),
            saltExitCode: $exitCode,
            durationSeconds: round(microtime(true) - $this->startedAt, 3),
        );
    }

    private function timedOut(ProcessTimedOutException $exception): SaltResult
    {
        $this->process->stop(0);

        return new SaltResult(
            ok: false,
            target: $this->saltCall->target,
            retcode: null,
            stdout: $this->process->getOutput(),
            stderr: $this->process->getErrorOutput(),
            error: sprintf(
                'Local salt subprocess exceeded %ds and was killed: %s',
                (int) $exception->getExceededTimeout(),
                $exception->getMessage(),
            ),
            durationSeconds: round(microtime(true) - $this->startedAt, 3),
        );
    }
}
