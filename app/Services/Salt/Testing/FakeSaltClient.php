<?php

declare(strict_types=1);

namespace App\Services\Salt\Testing;

use App\Enums\StepName;
use App\Services\Salt\Contracts\PendingSaltCall;
use App\Services\Salt\Contracts\SaltClient;
use App\Services\Salt\SaltCall;
use App\Services\Salt\SaltResult;
use Closure;
use PHPUnit\Framework\Assert;

/**
 * In-memory SaltClient for tests: records every call and answers from a stack of
 * queued responses, defaulting to success.
 */
final class FakeSaltClient implements SaltClient
{
    /** @var list<SaltCall> */
    private array $calls = [];

    /** @var list<Closure(SaltCall): ?SaltResult> */
    private array $handlers = [];

    public bool $defaultOk = true;

    /**
     * Queue a canned answer for the next call matching $step (and optionally
     * $target). Handlers are consumed in registration order.
     */
    public function respondTo(StepName $step, bool $ok, ?string $target = null, array $payload = []): self
    {
        $used = false;

        $this->handlers[] = function (SaltCall $call) use ($step, $ok, $target, $payload, &$used): ?SaltResult {
            if ($used || $call->step() !== $step) {
                return null;
            }

            if ($target !== null && $call->target !== $target) {
                return null;
            }

            $used = true;

            return $this->result($call, $ok, $payload);
        };

        return $this;
    }

    /**
     * Always answer calls for $step with $ok (no consumption).
     */
    public function alwaysRespondTo(StepName $step, bool $ok, array $payload = []): self
    {
        $this->handlers[] = fn (SaltCall $call): ?SaltResult => $call->step() === $step
            ? $this->result($call, $ok, $payload)
            : null;

        return $this;
    }

    public function run(SaltCall $call): SaltResult
    {
        return $this->start($call)->wait();
    }

    public function start(SaltCall $call): PendingSaltCall
    {
        $this->calls[] = $call;

        foreach ($this->handlers as $handler) {
            $result = $handler($call);

            if ($result instanceof SaltResult) {
                return new SettledSaltCall($call, $result);
            }
        }

        return new SettledSaltCall($call, $this->result($call, $this->defaultOk));
    }

    /**
     * @return list<SaltCall>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @return list<SaltCall>
     */
    public function callsFor(StepName $step): array
    {
        return array_values(array_filter($this->calls, fn (SaltCall $call) => $call->step() === $step));
    }

    /**
     * @return list<string> step names in the order they were invoked
     */
    public function stepSequence(): array
    {
        return array_map(fn (SaltCall $call) => $call->step()->value, $this->calls);
    }

    public function assertRan(StepName $step, ?string $target = null): void
    {
        foreach ($this->calls as $call) {
            if ($call->step() === $step && ($target === null || $call->target === $target)) {
                Assert::assertTrue(true);

                return;
            }
        }

        Assert::fail(sprintf(
            'Expected a salt call for step [%s]%s. Ran: %s',
            $step->value,
            $target === null ? '' : " on [{$target}]",
            implode(', ', $this->stepSequence()) ?: '(none)',
        ));
    }

    public function assertNeverRan(StepName $step, ?string $target = null): void
    {
        foreach ($this->calls as $call) {
            if ($call->step() === $step && ($target === null || $call->target === $target)) {
                Assert::fail(sprintf(
                    'Did not expect a salt call for step [%s]%s.',
                    $step->value,
                    $target === null ? '' : " on [{$target}]",
                ));
            }
        }

        Assert::assertTrue(true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function result(SaltCall $call, bool $ok, array $payload = []): SaltResult
    {
        $payload = array_merge(
            ['ok' => $ok, $ok ? 'detail' : 'error' => $call->step()->value.($ok ? ' ok' : ' failed')],
            $payload,
        );
        $payload['ok'] = $ok;

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);

        return new SaltResult(
            ok: $ok,
            target: $call->target,
            retcode: $ok ? 0 : 1,
            stdout: $encoded === false ? '' : $encoded,
            payload: $payload,
            error: $ok ? null : (string) ($payload['error'] ?? 'failed'),
        );
    }
}
