<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StepName;
use App\Services\Salt\ProcessSaltClient;
use App\Services\Salt\SaltCall;
use App\Services\Salt\ShimCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How the real Salt subprocess is launched.
 *
 * This is the one test in the suite that runs an actual process — against a stub
 * standing in for the salt binary, never a real one. It exists because of a failure
 * that cost an afternoon: the salt CLI creates `~/.salt` while parsing its own
 * arguments, so run as www-data (whose home is usually a root-owned /var/www) it
 * dies with exit 64 and a PermissionError traceback before contacting any minion.
 * The portal therefore hands the subprocess a HOME it is guaranteed to own.
 */
final class SaltSubprocessTest extends TestCase
{
    use RefreshDatabase;

    private string $stub;

    protected function setUp(): void
    {
        parent::setUp();

        // Reports $HOME back through the two layers of JSON the parser expects, so
        // the assertion is on what the subprocess actually saw.
        $this->stub = storage_path('framework/testing/fake-salt-'.getmypid());

        file_put_contents($this->stub, <<<'SH'
            #!/bin/sh
            payload="{\"ok\": true, \"detail\": \"$HOME\"}"
            escaped=$(printf '%s' "$payload" | sed 's/"/\\"/g')
            printf '{"staging": {"retcode": 0, "stdout": "%s", "stderr": ""}}\n' "$escaped"
            SH);

        chmod($this->stub, 0755);

        config(['mwdeploy.salt.binary' => $this->stub]);
    }

    protected function tearDown(): void
    {
        @unlink($this->stub);

        parent::tearDown();
    }

    #[Test]
    public function it_runs_salt_with_a_home_directory_the_web_user_owns(): void
    {
        $home = storage_path('framework/salt');

        config(['mwdeploy.salt.home' => $home]);

        $result = app(ProcessSaltClient::class)->run($this->gitHeadCall());

        $this->assertTrue($result->ok, (string) $result->error);
        $this->assertSame($home, $result->detail());
        $this->assertDirectoryExists($home, 'The salt home directory is created rather than assumed.');
    }

    #[Test]
    public function an_empty_configured_home_inherits_the_parent_environment(): void
    {
        // The escape hatch for a host where HOME is already set up correctly and
        // the operator would rather the portal did not second-guess it.
        config(['mwdeploy.salt.home' => '']);

        $result = app(ProcessSaltClient::class)->run($this->gitHeadCall());

        $this->assertTrue($result->ok, (string) $result->error);
        $this->assertSame(getenv('HOME') ?: '', $result->detail());
    }

    #[Test]
    public function a_missing_binary_fails_as_a_result_rather_than_an_exception(): void
    {
        config(['mwdeploy.salt.binary' => '/definitely/not/here/salt']);

        $result = app(ProcessSaltClient::class)->run($this->gitHeadCall());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('/definitely/not/here/salt', (string) $result->error);
    }

    private function gitHeadCall(): SaltCall
    {
        return new SaltCall(
            target: 'staging',
            command: ShimCommand::make(StepName::GitHead)->option('path', '/srv/mediawiki-staging'),
        );
    }
}
