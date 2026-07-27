<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\StepName;
use App\Services\Salt\ShimCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The shim command string is executed by a shell on the minion, so the quoting
 * here is a security boundary, not a formatting preference.
 */
final class ShimCommandTest extends TestCase
{
    #[Test]
    public function it_quotes_the_binary_verb_and_every_argument(): void
    {
        config(['mwdeploy.shim.binary' => '/usr/local/bin/mwdeploy-shim']);

        $command = ShimCommand::make(StepName::GitCheckout)
            ->option('path', '/srv/mediawiki-staging/versions/1.45/extensions/Echo')
            ->option('ref', 'master');

        $this->assertSame(
            "'/usr/local/bin/mwdeploy-shim' 'git-checkout' '--path' "
            ."'/srv/mediawiki-staging/versions/1.45/extensions/Echo' '--ref' 'master'",
            $command->toString(),
        );
    }

    #[Test]
    public function it_neutralises_shell_metacharacters_in_argument_values(): void
    {
        config(['mwdeploy.shim.binary' => 'mwdeploy-shim']);

        $command = ShimCommand::make(StepName::GitCheckout)
            ->option('path', '/srv/mediawiki-staging')
            ->option('ref', 'master; rm -rf /srv');

        // The injected command has to end up inside the quoted ref argument,
        // never as a second shell command.
        $this->assertSame(
            "'mwdeploy-shim' 'git-checkout' '--path' '/srv/mediawiki-staging' '--ref' 'master; rm -rf /srv'",
            $command->toString(),
        );
    }

    #[Test]
    public function it_escapes_embedded_single_quotes(): void
    {
        config(['mwdeploy.shim.binary' => 'mwdeploy-shim']);

        $command = ShimCommand::make(StepName::GitCheckout)
            ->option('path', '/srv')
            ->option('ref', "it's'; touch /tmp/pwned; '");

        $rendered = $command->toString();

        // Every embedded quote is closed, escaped and reopened, so the argument
        // stays a single shell word.
        $this->assertStringContainsString("'\\''", $rendered);
        $this->assertSame(0, substr_count($rendered, "; touch /tmp/pwned; '") - substr_count($rendered, "'\\''; touch"));
    }

    #[Test]
    public function optional_options_are_omitted_when_empty(): void
    {
        config(['mwdeploy.shim.binary' => 'shim']);

        $command = ShimCommand::make(StepName::Canary)
            ->optionalOption('vhost', null)
            ->optionalOption('branch', '')
            ->optionalOption('retries', 3);

        $this->assertSame("'shim' 'canary' '--retries' '3'", $command->toString());
    }

    #[Test]
    public function flags_are_only_emitted_when_enabled(): void
    {
        config(['mwdeploy.shim.binary' => 'shim']);

        $this->assertSame(
            "'shim' 'rsync-local' '--provision'",
            ShimCommand::make(StepName::RsyncLocal)->flag('provision', true)->toString(),
        );

        $this->assertSame(
            "'shim' 'rsync-local'",
            ShimCommand::make(StepName::RsyncLocal)->flag('provision', false)->toString(),
        );
    }

    #[Test]
    public function repeated_options_become_one_flag_pair_per_value(): void
    {
        config(['mwdeploy.shim.binary' => 'shim']);

        $command = ShimCommand::make(StepName::RsyncLocal)
            ->repeatedOption('path', ['versions/1.45/extensions/Echo', 'config']);

        $this->assertSame(
            "'shim' 'rsync-local' '--path' 'versions/1.45/extensions/Echo' '--path' 'config'",
            $command->toString(),
        );
    }

    #[Test]
    public function the_haproxy_verb_stays_two_shell_words(): void
    {
        config(['mwdeploy.shim.binary' => 'shim']);

        $command = ShimCommand::haproxy(StepName::HaproxyDepool, 'depool')
            ->option('proxy', 'proxy-1')
            ->option('backend', 'mediawiki')
            ->option('server', 'mw-us-east-011');

        $this->assertSame(
            "'shim' 'haproxy' 'depool' '--proxy' 'proxy-1' '--backend' 'mediawiki' '--server' 'mw-us-east-011'",
            $command->toString(),
        );
    }
}
