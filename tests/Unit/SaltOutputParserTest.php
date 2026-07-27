<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Salt\SaltOutputParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unwrapping Salt's envelope and the shim's result line is where a wrong answer
 * would be most dangerous: reading a failure as a success means the rollout
 * carries on past a broken step.
 */
final class SaltOutputParserTest extends TestCase
{
    private SaltOutputParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SaltOutputParser;
    }

    #[Test]
    public function it_reads_a_successful_cmd_run_all_return(): void
    {
        $salt = json_encode(['mw-us-east-011' => [
            'pid' => 4242,
            'retcode' => 0,
            'stdout' => '{"ok": true, "detail": "rsync-remote ok"}',
            'stderr' => '',
        ]]);

        $result = $this->parser->parse('mw-us-east-011', $salt, '', 0);

        $this->assertTrue($result->ok);
        $this->assertSame(0, $result->retcode);
        $this->assertSame('rsync-remote ok', $result->detail());
    }

    #[Test]
    public function it_reads_a_failed_shim_result_and_prefers_the_shims_own_error(): void
    {
        $salt = json_encode(['mw-us-east-011' => [
            'retcode' => 1,
            'stdout' => '{"ok": false, "error": "patch would not apply: Hunk #1 FAILED"}',
            'stderr' => 'noise on stderr',
        ]]);

        $result = $this->parser->parse('mw-us-east-011', $salt, '', 1);

        $this->assertFalse($result->ok);
        $this->assertSame('patch would not apply: Hunk #1 FAILED', $result->error);
    }

    #[Test]
    public function it_takes_the_last_json_line_when_a_subprocess_printed_first(): void
    {
        // rsync and git write plenty of their own output before the shim's verdict.
        $stdout = implode("\n", [
            'sending incremental file list',
            'versions/1.45/extensions/Echo/Echo.php',
            'not json at all {',
            '{"ok": true, "detail": "rsync-local ok", "transferred": 2}',
        ]);

        $salt = json_encode(['staging' => ['retcode' => 0, 'stdout' => $stdout, 'stderr' => '']]);

        $result = $this->parser->parse('staging', $salt, '', 0);

        $this->assertTrue($result->ok);
        $this->assertSame('rsync-local ok', $result->detail());
        $this->assertSame(2, $result->payloadValue('transferred'));
    }

    #[Test]
    public function a_nonzero_retcode_without_a_shim_payload_is_a_failure(): void
    {
        $salt = json_encode(['staging' => [
            'retcode' => 127,
            'stdout' => '',
            'stderr' => 'mwdeploy-shim: command not found',
        ]]);

        $result = $this->parser->parse('staging', $salt, '', 127);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('command not found', (string) $result->error);
        $this->assertStringContainsString('127', (string) $result->error);
    }

    #[Test]
    public function a_zero_retcode_with_no_output_is_treated_as_success(): void
    {
        $salt = json_encode(['staging' => ['retcode' => 0, 'stdout' => '', 'stderr' => '']]);

        $result = $this->parser->parse('staging', $salt, '', 0);

        $this->assertTrue($result->ok);
    }

    #[Test]
    public function it_supports_plain_cmd_run_where_only_stdout_comes_back(): void
    {
        $salt = json_encode(['staging' => '{"ok": true, "detail": "git checkout ok"}']);

        $result = $this->parser->parse('staging', $salt, '', 0);

        $this->assertTrue($result->ok);
        $this->assertSame('git checkout ok', $result->detail());
    }

    #[Test]
    public function an_unreachable_minion_is_a_failure_not_a_stdout_string(): void
    {
        $salt = json_encode(['mw-us-east-011' => 'Minion did not return. [No response]']);

        $result = $this->parser->parse('mw-us-east-011', $salt, '', 1);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('Minion did not return', (string) $result->error);
    }

    #[Test]
    public function unparseable_salt_output_is_a_failure(): void
    {
        $result = $this->parser->parse('staging', "Salt request timed out.\n", '', 1);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('Could not parse salt', (string) $result->error);
    }

    #[Test]
    public function a_salt_cli_that_never_started_is_reported_against_the_portal_host(): void
    {
        // Exit 64 with a Python traceback cannot have come from a minion: a minion's
        // output arrives inside the JSON envelope, not instead of it. Blaming the
        // fleet for this wastes the first ten minutes of an incident.
        $stderr = <<<'TXT'
        Usage: salt [options] '<target>' <function> [arguments]

        salt: error: Error while processing <function LogLevelMixIn.__setup_logfile_logger_config>:
        Traceback (most recent call last):
          File "/opt/saltstack/salt/lib/python3.14/site-packages/salt/utils/parsers.py", line 864, in __setup_logfile_logger_config
            os.makedirs(user_salt_dir, 0o750)
        PermissionError: [Errno 13] Permission denied: '/var/www/.salt'
        TXT;

        $result = $this->parser->parse('staging', '', $stderr, 64);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('local salt CLI refused to run', (string) $result->error);
        $this->assertStringContainsString('nothing was sent to any minion', (string) $result->error);
        // …and the one trap everybody hits gets told how to fix it.
        $this->assertStringContainsString('MWDEPLOY_SALT_HOME', (string) $result->error);
    }

    #[Test]
    public function a_usage_error_without_the_home_problem_gets_no_misleading_hint(): void
    {
        $result = $this->parser->parse('staging', '', "Usage: salt [options] '<target>' <function>\n", 64);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('local salt CLI refused to run', (string) $result->error);
        $this->assertStringNotContainsString('MWDEPLOY_SALT_HOME', (string) $result->error);
    }

    #[Test]
    public function a_missing_entry_for_the_requested_minion_is_a_failure(): void
    {
        // Two minions came back but neither is the one we targeted: this is a
        // targeting mistake, and silently using the wrong host's result would be
        // much worse than failing.
        $salt = json_encode([
            'other-host-a' => ['retcode' => 0, 'stdout' => '{"ok": true}'],
            'other-host-b' => ['retcode' => 0, 'stdout' => '{"ok": true}'],
        ]);

        $result = $this->parser->parse('mw-us-east-011', $salt, '', 0);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('no result for minion', (string) $result->error);
    }

    #[Test]
    public function it_falls_back_to_the_sole_entry_when_salt_renamed_the_key(): void
    {
        $salt = json_encode(['staging.internal' => ['retcode' => 0, 'stdout' => '{"ok": true, "detail": "ok"}']]);

        $result = $this->parser->parse('staging', $salt, '', 0);

        $this->assertTrue($result->ok);
    }

    #[Test]
    public function it_reads_a_shim_payload_that_landed_on_stderr(): void
    {
        $salt = json_encode(['staging' => [
            'retcode' => 1,
            'stdout' => '',
            'stderr' => '{"ok": false, "error": "no such directory: /srv/nope"}',
        ]]);

        $result = $this->parser->parse('staging', $salt, '', 1);

        $this->assertFalse($result->ok);
        $this->assertSame('no such directory: /srv/nope', $result->error);
    }
}
