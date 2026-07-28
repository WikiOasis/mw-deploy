<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DeploymentDecision;
use App\Enums\DeploymentIntent;
use App\Enums\DeploymentStatus;
use App\Enums\RefType;
use App\Enums\StepName;
use App\Enums\StepStatus;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\DeploymentRepoRef;
use App\Models\DeployTarget;
use App\Models\MediaWikiVersion;
use App\Models\Patch;
use App\Models\RepositoryVersion;
use App\Models\User;
use App\Services\Deployment\DeploymentAuthorizer;
use App\Services\Deployment\DeploymentRunner;
use App\Services\Salt\SaltCall;
use App\Services\Salt\Testing\FakeSaltClient;
use App\Support\DeploymentOptions;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AutoAnsweringDecisionGate;
use Tests\TestCase;

/**
 * Sequencing, canary prompts, pooling and the permission re-check in the job —
 * the behaviour that is independent of what is being deployed.
 */
final class RolloutBehaviourTest extends TestCase
{
    use RefreshDatabase;

    private FakeSaltClient $salt;

    private AutoAnsweringDecisionGate $decisions;

    private User $actor;

    private MediaWikiVersion $version;

    private RepositoryVersion $echo;

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
        $this->version = $this->version('1.45');
        $this->echo = $this->extension('Echo', $this->version, 'REL1_45');
    }

    #[Test]
    public function a_happy_path_deployment_runs_the_whole_sequence_in_order(): void
    {
        $server = DeployTarget::factory()->create(['hostname' => 'mw-us-east-011']);
        $proxy = DeployTarget::factory()->proxy()->create(['hostname' => 'proxy-1']);

        $deployment = $this->deployment(new DeploymentOptions(
            servers: [$server->hostname],
            rollout: true,
            l10n: true,
        ));

        $this->runJob($deployment);

        $this->assertSame(DeploymentStatus::Done, $deployment->fresh()->status);

        // Read HEAD before touching anything, depool before rsync, canary before
        // repool.
        $this->assertSame([
            StepName::GitHead->value,
            StepName::GitCheckout->value,
            StepName::RsyncLocal->value,
            StepName::L10nRebuild->value,
            StepName::Canary->value,
            StepName::HaproxyDepool->value,
            StepName::RsyncRemote->value,
            StepName::L10nRebuild->value,
            StepName::Canary->value,
            StepName::HaproxyRepool->value,
        ], $this->salt->stepSequence());

        $this->assertSame('staging', $this->salt->callsFor(StepName::RsyncLocal)[0]->target);
        $this->assertSame($server->hostname, $this->salt->callsFor(StepName::RsyncRemote)[0]->target);
        $this->assertSame($proxy->hostname, $this->salt->callsFor(StepName::HaproxyDepool)[0]->target);

        $this->assertSame(10, $deployment->fresh()->steps()->where('status', StepStatus::Done->value)->count());
    }

    #[Test]
    public function a_failed_git_checkout_aborts_before_anything_is_rsynced(): void
    {
        DeployTarget::factory()->create();

        $this->salt->alwaysRespondTo(StepName::GitCheckout, false);

        $deployment = $this->deployment();

        $this->runJob($deployment);

        $this->assertSame(DeploymentStatus::Failed, $deployment->fresh()->status);
        $this->assertStringContainsString('git checkout failed', (string) $deployment->fresh()->failure_reason);
        $this->salt->assertNeverRan(StepName::RsyncLocal);
        $this->salt->assertNeverRan(StepName::RsyncRemote);
    }

    #[Test]
    public function the_appserver_canary_pins_the_vhost_to_the_targets_ip_when_one_is_set(): void
    {
        $server = DeployTarget::factory()->create([
            'hostname' => 'mw-us-east-011',
            'ip_address' => '10.0.4.12',
        ]);

        $this->runJob($this->deployment(new DeploymentOptions(servers: [$server->hostname])));

        $calls = $this->salt->callsFor(StepName::Canary);
        $appserverCall = collect($calls)->first(fn (SaltCall $call) => $call->target === $server->hostname);

        $this->assertNotNull($appserverCall);
        $this->assertStringContainsString("'--host' '10.0.4.12'", $appserverCall->command->toString());
    }

    #[Test]
    public function the_appserver_canary_omits_host_when_the_target_has_no_ip_recorded(): void
    {
        // Without one it falls back to the shim's own 127.0.0.1 default — worth
        // pinning down so a future change doesn't silently start sending an empty
        // --host that breaks the check instead of merely not improving it.
        $server = DeployTarget::factory()->create(['hostname' => 'mw-us-east-011']);

        $this->runJob($this->deployment(new DeploymentOptions(servers: [$server->hostname])));

        $calls = $this->salt->callsFor(StepName::Canary);
        $appserverCall = collect($calls)->first(fn (SaltCall $call) => $call->target === $server->hostname);

        $this->assertNotNull($appserverCall);
        $this->assertStringNotContainsString('--host', $appserverCall->command->toString());
    }

    #[Test]
    public function a_staging_canary_failure_parks_on_a_decision_and_abort_stops_the_rollout(): void
    {
        DeployTarget::factory()->create();

        $this->salt->alwaysRespondTo(StepName::Canary, false);
        $this->decisions->answerWith(DeploymentDecision::Abort);

        $deployment = $this->deployment();

        $this->runJob($deployment);

        $this->assertSame(DeploymentStatus::Failed, $deployment->fresh()->status);
        $this->assertStringContainsString('staging canary failed', (string) $deployment->fresh()->failure_reason);
        $this->salt->assertNeverRan(StepName::RsyncRemote);

        // "Abort only" must not enqueue a rollback.
        $this->assertSame(0, Deployment::query()->whereNotNull('rolls_back_deployment_id')->count());
    }

    #[Test]
    public function abort_and_rollback_enqueues_a_rollback_to_the_previous_ref(): void
    {
        DeployTarget::factory()->create();

        $this->salt
            ->alwaysRespondTo(StepName::GitHead, true, ['ref' => 'oldsha1234', 'ref_type' => 'commit'])
            ->alwaysRespondTo(StepName::Canary, false);
        $this->decisions->answerWith(DeploymentDecision::AbortAndRollback);

        $deployment = $this->deployment();

        $this->runJob($deployment);

        $rollback = Deployment::query()->where('rolls_back_deployment_id', $deployment->getKey())->firstOrFail();

        $this->assertSame(DeploymentStatus::Pending, $rollback->status);

        $ref = $rollback->repoRefs()->firstOrFail();
        $this->assertSame('oldsha1234', $ref->ref_value);
        $this->assertSame(RefType::Commit, $ref->ref_type);
    }

    #[Test]
    public function continuing_through_a_canary_failure_carries_on_to_the_rollout(): void
    {
        $server = DeployTarget::factory()->create();

        $this->salt->respondTo(StepName::Canary, false, target: 'staging');
        $this->decisions->answerWith(DeploymentDecision::Continue);

        $deployment = $this->deployment(new DeploymentOptions(servers: [$server->hostname]));

        $this->runJob($deployment);

        $this->assertSame(DeploymentStatus::Done, $deployment->fresh()->status);
        $this->salt->assertRan(StepName::RsyncRemote, $server->hostname);
    }

    #[Test]
    public function force_skips_the_prompt_entirely_and_never_rolls_back(): void
    {
        $server = DeployTarget::factory()->create();

        $this->salt->alwaysRespondTo(StepName::Canary, false);

        $deployment = $this->deployment(new DeploymentOptions(servers: [$server->hostname], force: true));

        $this->runJob($deployment);

        $this->assertNull($deployment->fresh()->pending_decision);
        $this->assertSame(0, $this->decisions->promptCount());
        $this->salt->assertRan(StepName::RsyncRemote, $server->hostname);
        $this->assertSame(DeploymentStatus::Done, $deployment->fresh()->status);
        $this->assertSame(0, Deployment::query()->whereNotNull('rolls_back_deployment_id')->count());
    }

    #[Test]
    public function an_unanswered_prompt_falls_back_to_the_configured_default(): void
    {
        config([
            'mwdeploy.decisions.timeout' => 1,
            'mwdeploy.decisions.timeout_default' => DeploymentDecision::AbortAndRollback->value,
        ]);

        DeployTarget::factory()->create();

        $this->salt
            ->alwaysRespondTo(StepName::GitHead, true, ['ref' => 'oldsha', 'ref_type' => 'commit'])
            ->alwaysRespondTo(StepName::Canary, false);

        $deployment = $this->deployment();

        $this->runJob($deployment);

        // Leaving the farm parked mid-rollout is worse than either answer.
        $this->assertSame(DeploymentStatus::Failed, $deployment->fresh()->status);
        $this->assertDatabaseHas('deployments', ['rolls_back_deployment_id' => $deployment->getKey()]);
        $this->assertSame(1, $this->decisions->promptCount());
    }

    #[Test]
    public function a_failed_appserver_canary_still_repools_that_server(): void
    {
        $server = DeployTarget::factory()->create(['hostname' => 'mw-01']);
        DeployTarget::factory()->proxy()->create(['hostname' => 'proxy-1']);

        $this->salt->respondTo(StepName::Canary, true, target: 'staging')
            ->alwaysRespondTo(StepName::Canary, false);
        $this->decisions->answerWith(DeploymentDecision::Abort);

        $deployment = $this->deployment(new DeploymentOptions(servers: [$server->hostname], rollout: true));

        $this->runJob($deployment);

        // A depooled server left out of the pool is its own outage.
        $this->salt->assertRan(StepName::HaproxyRepool, 'proxy-1');
        $this->assertSame(DeploymentStatus::Aborted, $deployment->fresh()->status);
    }

    #[Test]
    public function a_failed_depool_stops_that_server_being_touched(): void
    {
        $server = DeployTarget::factory()->create();
        DeployTarget::factory()->proxy()->create();

        $this->salt->alwaysRespondTo(StepName::HaproxyDepool, false);

        $deployment = $this->deployment(new DeploymentOptions(servers: [$server->hostname], rollout: true));

        $this->runJob($deployment);

        $this->salt->assertNeverRan(StepName::RsyncRemote);
        $this->assertSame(DeploymentStatus::Failed, $deployment->fresh()->status);
    }

    #[Test]
    public function aborting_mid_rollout_skips_the_servers_it_never_reached(): void
    {
        DeployTarget::factory()->create(['hostname' => 'mw-01', 'sort_order' => 1]);
        DeployTarget::factory()->create(['hostname' => 'mw-02', 'sort_order' => 2]);

        $this->salt->respondTo(StepName::Canary, true, target: 'staging')
            ->alwaysRespondTo(StepName::Canary, false);
        $this->decisions->answerWith(DeploymentDecision::Abort);

        $deployment = $this->deployment(new DeploymentOptions(servers: ['mw-01', 'mw-02'], parallel: 1));

        $this->runJob($deployment);

        $this->salt->assertRan(StepName::RsyncRemote, 'mw-01');
        $this->salt->assertNeverRan(StepName::RsyncRemote, 'mw-02');

        $this->assertDatabaseHas('deployment_steps', [
            'deployment_id' => $deployment->getKey(),
            'target_hostname' => 'mw-02',
            'status' => StepStatus::Skipped->value,
        ]);
    }

    #[Test]
    public function parallelism_keeps_more_than_one_server_in_flight(): void
    {
        foreach (['mw-01', 'mw-02', 'mw-03'] as $index => $hostname) {
            DeployTarget::factory()->create(['hostname' => $hostname, 'sort_order' => $index]);
        }

        $deployment = $this->deployment(new DeploymentOptions(parallel: 3));

        $this->runJob($deployment);

        $this->assertSame(DeploymentStatus::Done, $deployment->fresh()->status);
        $this->assertCount(3, $this->salt->callsFor(StepName::RsyncRemote));
    }

    #[Test]
    public function an_empty_server_list_targets_every_active_appserver(): void
    {
        DeployTarget::factory()->create(['hostname' => 'mw-01']);
        DeployTarget::factory()->create(['hostname' => 'mw-02']);
        DeployTarget::factory()->inactive()->create(['hostname' => 'mw-retired']);

        $this->runJob($this->deployment(new DeploymentOptions(servers: [])));

        $targets = array_map(fn (SaltCall $call) => $call->target, $this->salt->callsFor(StepName::RsyncRemote));
        sort($targets);

        $this->assertSame(['mw-01', 'mw-02'], $targets);
    }

    #[Test]
    public function staging_only_never_touches_an_appserver(): void
    {
        DeployTarget::factory()->create();

        $deployment = $this->deployment(new DeploymentOptions(stagingOnly: true));

        $this->runJob($deployment);

        $this->assertSame(DeploymentStatus::Done, $deployment->fresh()->status);
        $this->salt->assertRan(StepName::RsyncLocal, 'staging');
        $this->salt->assertNeverRan(StepName::RsyncRemote);
    }

    #[Test]
    public function a_patch_failure_aborts_and_is_recorded_against_the_checkout(): void
    {
        DeployTarget::factory()->create();

        $patch = Patch::factory()->forCheckout($this->echo)->create(['name' => 'T12345 hotfix']);

        $this->salt->alwaysRespondTo(StepName::PatchApply, false);

        $deployment = $this->deployment(new DeploymentOptions(stagingOnly: true));
        $deployment->deploymentPatches()->create(['patch_id' => $patch->getKey(), 'applied' => false]);

        $this->runJob($deployment->fresh());

        $this->assertSame(DeploymentStatus::Failed, $deployment->fresh()->status);
        $this->assertStringContainsString('T12345 hotfix', (string) $deployment->fresh()->failure_reason);
        $this->salt->assertNeverRan(StepName::RsyncLocal);
    }

    #[Test]
    public function a_successful_patch_records_the_ref_it_was_applied_against(): void
    {
        DeployTarget::factory()->create();

        $patch = Patch::factory()->forCheckout($this->echo)->create();

        $deployment = $this->deployment(new DeploymentOptions(stagingOnly: true));
        $deployment->deploymentPatches()->create(['patch_id' => $patch->getKey(), 'applied' => false]);

        $this->runJob($deployment->fresh());

        $this->assertDatabaseHas('deployment_patches', [
            'deployment_id' => $deployment->getKey(),
            'patch_id' => $patch->getKey(),
            'applied' => true,
            'applied_to_ref' => 'REL1_45',
        ]);
    }

    #[Test]
    public function a_rollback_that_fails_its_canary_is_not_rolled_back_again(): void
    {
        DeployTarget::factory()->create();

        $original = $this->deployment();
        $original->update(['status' => DeploymentStatus::Failed->value]);
        $original->snapshots()->create([
            'repository_version_id' => $this->echo->getKey(),
            'previous_present' => true,
            'previous_ref_type' => RefType::Commit->value,
            'previous_ref_value' => 'oldsha',
            'new_present' => true,
            'new_ref_type' => RefType::Branch->value,
            'new_ref_value' => 'REL1_45',
        ]);

        $rollback = Deployment::factory()->create([
            'created_by' => $this->actor->getKey(),
            'rolls_back_deployment_id' => $original->getKey(),
            'options' => (new DeploymentOptions(stagingOnly: true))->toArray(),
        ]);
        DeploymentRepoRef::factory()->commit('oldsha')->create([
            'deployment_id' => $rollback->getKey(),
            'repository_version_id' => $this->echo->getKey(),
        ]);

        $this->salt->alwaysRespondTo(StepName::Canary, false);
        $this->decisions->answerWith(DeploymentDecision::AbortAndRollback);

        $this->runJob($rollback->fresh());

        // One automatic hop only.
        $this->assertSame(DeploymentStatus::Failed, $rollback->fresh()->status);
        $this->assertStringContainsString('Manual intervention required', (string) $rollback->fresh()->failure_reason);
        $this->assertSame(0, Deployment::query()->where('rolls_back_deployment_id', $rollback->getKey())->count());
    }

    #[Test]
    public function the_job_refuses_a_deployment_whose_creator_lacks_permission(): void
    {
        DeployTarget::factory()->create();

        $this->actor = $this->userWithPermissions([Permissions::DEPLOY_SKIN]);

        $deployment = $this->deployment();

        $this->runJob($deployment);

        $this->assertSame(DeploymentStatus::Failed, $deployment->fresh()->status);
        $this->assertStringContainsString('Refused', (string) $deployment->fresh()->failure_reason);
        $this->assertSame([], $this->salt->calls());
    }

    #[Test]
    public function the_job_refuses_an_undeploy_from_someone_who_may_only_deploy(): void
    {
        DeployTarget::factory()->create();

        $this->actor = $this->userWithPermissions([
            Permissions::DEPLOY_EXTENSION,
            Permissions::DEPLOY_PRODUCTION_SERVERS,
        ]);

        $deployment = Deployment::factory()->intent(DeploymentIntent::Undeploy)->create([
            'created_by' => $this->actor->getKey(),
            'options' => (new DeploymentOptions)->toArray(),
        ]);
        DeploymentRepoRef::factory()->undeploy()->create([
            'deployment_id' => $deployment->getKey(),
            'repository_version_id' => $this->echo->getKey(),
        ]);

        $this->runJob($deployment->fresh());

        // Being trusted to update Echo is not being trusted to delete it.
        $this->assertStringContainsString('may not remove', (string) $deployment->fresh()->failure_reason);
        $this->assertSame([], $this->salt->calls());
    }

    #[Test]
    public function the_job_refuses_a_deployer_who_has_not_enrolled_two_factor(): void
    {
        DeployTarget::factory()->create();

        $this->actor = $this->userWithPermissions(
            [Permissions::DEPLOY_EXTENSION, Permissions::DEPLOY_PRODUCTION_SERVERS],
            twoFactor: false,
        );

        $deployment = $this->deployment();

        $this->runJob($deployment);

        $this->assertStringContainsString('two-factor', (string) $deployment->fresh()->failure_reason);
        $this->assertSame([], $this->salt->calls());
    }

    #[Test]
    public function a_deployment_that_is_not_pending_is_left_alone(): void
    {
        $deployment = $this->deployment();
        $deployment->update(['status' => DeploymentStatus::Running->value]);

        $this->runJob($deployment->fresh());

        // Re-running a job for an in-flight deployment must not double-deploy.
        $this->assertSame([], $this->salt->calls());
        $this->assertSame(DeploymentStatus::Running, $deployment->fresh()->status);
    }

    #[Test]
    public function every_step_records_the_exact_salt_command_that_ran(): void
    {
        $deployment = $this->deployment(new DeploymentOptions(stagingOnly: true));

        $this->runJob($deployment);

        $step = $deployment->fresh()->steps()->where('step_name', StepName::GitCheckout->value)->firstOrFail();

        $this->assertStringContainsString('--out=json', (string) $step->command);
        $this->assertStringContainsString('mwdeploy-shim', (string) $step->command);
        $this->assertStringContainsString('git-checkout', (string) $step->command);
    }

    private function deployment(?DeploymentOptions $options = null): Deployment
    {
        $deployment = Deployment::factory()->create([
            'created_by' => $this->actor->getKey(),
            'options' => ($options ?? new DeploymentOptions)->toArray(),
        ]);

        DeploymentRepoRef::factory()->create([
            'deployment_id' => $deployment->getKey(),
            'repository_version_id' => $this->echo->getKey(),
            'ref_value' => 'REL1_45',
        ]);

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
