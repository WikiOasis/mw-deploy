<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DeploymentIntent;
use App\Enums\DeploymentStatus;
use App\Enums\PresenceStatus;
use App\Enums\RepoAction;
use App\Enums\StepName;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\DeploymentRepoRef;
use App\Models\DeployTarget;
use App\Models\MediaWikiVersion;
use App\Models\Repository;
use App\Models\RepositoryVersion;
use App\Models\User;
use App\Services\Deployment\DeploymentAuthorizer;
use App\Services\Deployment\DeploymentRunner;
use App\Services\Salt\SaltCall;
use App\Services\Salt\Testing\FakeSaltClient;
use App\Support\DeploymentOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AutoAnsweringDecisionGate;
use Tests\TestCase;

/**
 * Deploying across core versions: one version, several, or all of them, each at
 * its own pinned ref.
 */
final class MultiversionDeploymentTest extends TestCase
{
    use RefreshDatabase;

    private FakeSaltClient $salt;

    private AutoAnsweringDecisionGate $decisions;

    private User $actor;

    private MediaWikiVersion $v45;

    private MediaWikiVersion $v46;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mwdeploy.targets.staging' => 'staging',
            'mwdeploy.shim.binary' => '/usr/local/bin/mwdeploy-shim',
            'mwdeploy.paths.staging' => '/srv/mediawiki-staging',
            'mwdeploy.paths.production' => '/srv/mediawiki',
            'mwdeploy.decisions.timeout' => 0,
        ]);

        Queue::fake();

        $this->salt = $this->fakeSalt();
        $this->decisions = $this->fakeDecisions();
        $this->actor = $this->admin();

        $this->v45 = $this->version('1.45');
        $this->v46 = $this->version('1.46');
    }

    #[Test]
    public function deploying_one_extension_to_every_version_uses_each_versions_own_pin(): void
    {
        $echo45 = $this->extension('Echo', $this->v45, 'REL1_45');
        $echo46 = $this->extension('Echo', $this->v46, 'REL1_46');

        DeployTarget::factory()->create(['hostname' => 'mw-01']);

        $deployment = $this->deployment([
            [$echo45, $echo45->resolvedRefValue()],
            [$echo46, $echo46->resolvedRefValue()],
        ]);

        $this->runJob($deployment);

        $this->assertSame(DeploymentStatus::Done, $deployment->fresh()->status);

        $checkouts = array_map(
            fn (SaltCall $call) => $call->command->toString(),
            $this->salt->callsFor(StepName::GitCheckout),
        );

        // The whole point of per-version pins: one action, two different refs, in
        // two different directories.
        $this->assertCount(2, $checkouts);
        $this->assertStringContainsString("'/srv/mediawiki-staging/versions/1.45/extensions/Echo' '--ref' 'REL1_45'", $checkouts[0]);
        $this->assertStringContainsString("'/srv/mediawiki-staging/versions/1.46/extensions/Echo' '--ref' 'REL1_46'", $checkouts[1]);
    }

    #[Test]
    public function the_rsync_is_restricted_to_the_versions_actually_touched(): void
    {
        $echo45 = $this->extension('Echo', $this->v45, 'REL1_45');
        $this->extension('Echo', $this->v46, 'REL1_46');

        $deployment = $this->deployment([[$echo45, 'REL1_45']], new DeploymentOptions(stagingOnly: true));

        $this->runJob($deployment);

        $command = $this->salt->callsFor(StepName::RsyncLocal)[0]->command->toString();

        $this->assertStringContainsString("'--path' 'versions/1.45/extensions/Echo'", $command);
        // 1.46 was not part of this deployment and must not be walked.
        $this->assertStringNotContainsString('1.46', $command);
    }

    #[Test]
    public function deploying_a_core_version_syncs_that_whole_tree(): void
    {
        $core = $this->core($this->v46, 'REL1_46');

        $deployment = $this->deployment([[$core, 'REL1_46']], new DeploymentOptions(stagingOnly: true));

        $this->runJob($deployment);

        // A version bump touches too much to express as a path list.
        $this->assertStringNotContainsString(
            "'--path'",
            $this->salt->callsFor(StepName::RsyncLocal)[0]->command->toString(),
        );
    }

    #[Test]
    public function a_checkout_that_is_not_on_disk_is_cloned_before_it_is_checked_out(): void
    {
        $echo = RepositoryVersion::factory()
            ->of($this->extension('Echo', $this->v45)->repository, $this->v46)
            ->undeployed()
            ->pinnedTo('REL1_46')
            ->create();

        $deployment = $this->deployment([[$echo, 'REL1_46']], new DeploymentOptions(stagingOnly: true));

        $this->runJob($deployment);

        // Register then checkout, in that order — git-checkout against a directory
        // that does not exist would just fail.
        $sequence = $this->salt->stepSequence();
        $registerAt = array_search(StepName::RepoRegister->value, $sequence, true);
        $checkoutAt = array_search(StepName::GitCheckout->value, $sequence, true);

        $this->assertNotFalse($registerAt);
        $this->assertLessThan($checkoutAt, $registerAt);

        // And the registry now agrees it is on disk.
        $this->assertSame(PresenceStatus::Present, $echo->fresh()->status);
    }

    #[Test]
    public function a_checkout_already_on_disk_is_not_re_cloned(): void
    {
        $echo = $this->extension('Echo', $this->v45, 'REL1_45');

        $deployment = $this->deployment([[$echo, 'REL1_45']], new DeploymentOptions(stagingOnly: true));

        $this->runJob($deployment);

        $this->salt->assertNeverRan(StepName::RepoRegister);
    }

    #[Test]
    public function a_snapshot_is_recorded_per_checkout_with_its_own_previous_ref(): void
    {
        $echo45 = $this->extension('Echo', $this->v45, 'REL1_45');
        $echo46 = $this->extension('Echo', $this->v46, 'REL1_46');

        $this->salt->alwaysRespondTo(StepName::GitHead, true, ['ref' => 'oldsha', 'ref_type' => 'commit']);

        $deployment = $this->deployment([
            [$echo45, 'REL1_45'],
            [$echo46, 'REL1_46'],
        ], new DeploymentOptions(stagingOnly: true));

        $this->runJob($deployment);

        $snapshots = $deployment->fresh()->snapshots()->get()->keyBy('repository_version_id');

        $this->assertCount(2, $snapshots);

        foreach ([$echo45, $echo46] as $checkout) {
            $snapshot = $snapshots[$checkout->getKey()];

            $this->assertTrue($snapshot->previous_present);
            $this->assertSame('oldsha', $snapshot->previous_ref_value);
            $this->assertTrue($snapshot->new_present);
        }
    }

    #[Test]
    public function an_unversioned_checkout_lands_outside_the_version_tree(): void
    {
        $config = RepositoryVersion::factory()
            ->of(Repository::factory()->config()->create(), null)
            ->pinnedTo('master')
            ->create();

        $this->assertSame('config', $config->path);

        $deployment = $this->deployment([[$config, 'master']], new DeploymentOptions(stagingOnly: true));

        $this->runJob($deployment);

        $this->assertStringContainsString(
            "'--path' 'config'",
            $this->salt->callsFor(StepName::RsyncLocal)[0]->command->toString(),
        );
    }

    /**
     * @param  list<array{0: RepositoryVersion, 1: ?string}>  $items
     */
    private function deployment(
        array $items,
        ?DeploymentOptions $options = null,
        DeploymentIntent $intent = DeploymentIntent::Deploy,
    ): Deployment {
        $deployment = Deployment::factory()->intent($intent)->create([
            'created_by' => $this->actor->getKey(),
            'options' => ($options ?? new DeploymentOptions)->toArray(),
        ]);

        foreach ($items as [$checkout, $ref]) {
            DeploymentRepoRef::factory()->create([
                'deployment_id' => $deployment->getKey(),
                'repository_version_id' => $checkout->getKey(),
                'action' => $ref === null ? RepoAction::Undeploy->value : RepoAction::Deploy->value,
                'ref_type' => $ref === null ? null : 'branch',
                'ref_value' => $ref,
            ]);
        }

        return $deployment->fresh();
    }

    private function runJob(Deployment $deployment): void
    {
        (new RunDeployment($deployment->getKey()))->handle(
            app(DeploymentRunner::class),
            app(DeploymentAuthorizer::class),
        );
    }
}
