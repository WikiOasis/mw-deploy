<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DeploymentDecision;
use App\Enums\DeploymentStatus;
use App\Enums\RefType;
use App\Enums\StepName;
use App\Enums\StepStatus;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\DeployTarget;
use App\Models\Patch;
use App\Models\Repository;
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
 * The orchestration from section 5, end to end, against an in-memory Salt.
 */
final class RunDeploymentTest extends TestCase
{
    use RefreshDatabase;

    private FakeSaltClient $salt;

    private AutoAnsweringDecisionGate $decisions;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mwdeploy.targets.staging' => 'staging',
            'mwdeploy.shim.binary' => '/usr/local/bin/mwdeploy-shim',
            'mwdeploy.paths.staging' => '/srv/mediawiki-staging',
            'mwdeploy.paths.production' => '/srv/mediawiki',
            'mwdeploy.decisions.timeout' => 0, // no timeout unless a test sets one
        ]);

        // The job under test is invoked directly, so faking the queue only
        // intercepts the *nested* dispatch of an automatic rollback. That keeps
        // each test to one deployment instead of cascading into the rollback's
        // own run, which is covered separately.
        Queue::fake();

        $this->salt = $this->fakeSalt();
        $this->decisions = $this->fakeDecisions();
        $this->actor = $this->admin();
    }

    #[Test]
    public function a_happy_path_deployment_runs_the_whole_section_5_sequence(): void
    {
        $repository = Repository::factory()->create(['name' => 'Echo']);
        $server = DeployTarget::factory()->create(['hostname' => 'mw-us-east-011']);
        $proxy = DeployTarget::factory()->proxy()->create(['hostname' => 'proxy-1']);

        $deployment = $this->deployment($repository, new DeploymentOptions(
            servers: [$server->hostname],
            rollout: true,
            l10n: true,
        ));

        $this->runJob($deployment);

        $this->assertSame(DeploymentStatus::Done, $deployment->fresh()->status);

        // The order matters: read HEAD before touching anything, depool before
        // rsync, canary before repool.
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

        // Preparation runs on staging, rollout on the appserver, pooling on the proxy.
        $this->assertSame('staging', $this->salt->callsFor(StepName::RsyncLocal)[0]->target);
        $this->assertSame($server->hostname, $this->salt->callsFor(StepName::RsyncRemote)[0]->target);
        $this->assertSame($proxy->hostname, $this->salt->callsFor(StepName::HaproxyDepool)[0]->target);

        $this->assertSame(
            10,
            $deployment->fresh()->steps()->where('status', StepStatus::Done->value)->count(),
        );
    }

    #[Test]
    public function it_records_the_undo_point_before_checking_anything_out(): void
    {
        $repository = Repository::factory()->create();
        DeployTarget::factory()->create();

        $this->salt->alwaysRespondTo(StepName::GitHead, true, [
            'ref' => 'abc1234def5678',
            'ref_type' => 'commit',
        ]);

        $deployment = $this->deployment($repository, new DeploymentOptions(stagingOnly: true));

        $this->runJob($deployment);

        $snapshot = $deployment->fresh()->snapshots()->firstOrFail();

        $this->assertSame('abc1234def5678', $snapshot->previous_ref_value);
        $this->assertSame(RefType::Commit, $snapshot->previous_ref_type);
        $this->assertSame('master', $snapshot->new_ref_value);
        $this->assertTrue($snapshot->isRollbackable());
    }

    #[Test]
    public function a_failed_git_checkout_aborts_before_anything_is_rsynced(): void
    {
        $repository = Repository::factory()->create();
        DeployTarget::factory()->create();

        $this->salt->alwaysRespondTo(StepName::GitCheckout, false);

        $deployment = $this->deployment($repository);

        $this->runJob($deployment);

        $deployment->refresh();

        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertStringContainsString('git checkout failed', (string) $deployment->failure_reason);
        $this->salt->assertNeverRan(StepName::RsyncLocal);
        $this->salt->assertNeverRan(StepName::RsyncRemote);
    }

    #[Test]
    public function a_staging_canary_failure_parks_on_a_decision_and_aborting_stops_the_rollout(): void
    {
        $repository = Repository::factory()->create();
        DeployTarget::factory()->create();

        $this->salt->alwaysRespondTo(StepName::Canary, false);

        $deployment = $this->deployment($repository);

        // Stand in for an operator answering the modal as soon as it appears.
        $this->decisions->answerWith(DeploymentDecision::Abort);

        $this->runJob($deployment);

        $deployment->refresh();

        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertStringContainsString('staging canary failed', (string) $deployment->failure_reason);
        $this->salt->assertNeverRan(StepName::RsyncRemote);

        // "Abort only" must not enqueue a rollback.
        $this->assertSame(0, Deployment::query()->whereNotNull('rolls_back_deployment_id')->count());
    }

    #[Test]
    public function abort_and_rollback_enqueues_a_rollback_to_the_previous_ref(): void
    {
        $repository = Repository::factory()->create();
        DeployTarget::factory()->create();

        $this->salt
            ->alwaysRespondTo(StepName::GitHead, true, ['ref' => 'oldsha1234', 'ref_type' => 'commit'])
            ->alwaysRespondTo(StepName::Canary, false);

        $deployment = $this->deployment($repository);
        $this->decisions->answerWith(DeploymentDecision::AbortAndRollback);

        $this->runJob($deployment);

        $rollback = Deployment::query()->where('rolls_back_deployment_id', $deployment->getKey())->firstOrFail();

        $this->assertSame(DeploymentStatus::Pending, $rollback->status);
        $this->assertTrue($rollback->isRollback());

        $ref = $rollback->repoRefs()->firstOrFail();
        $this->assertSame('oldsha1234', $ref->ref_value);
        $this->assertSame(RefType::Commit, $ref->ref_type);
    }

    #[Test]
    public function continuing_through_a_canary_failure_carries_on_to_the_rollout(): void
    {
        $repository = Repository::factory()->create();
        $server = DeployTarget::factory()->create();

        // Fail only the staging canary; the appserver's canary passes.
        $this->salt->respondTo(StepName::Canary, false, target: 'staging');

        $deployment = $this->deployment($repository, new DeploymentOptions(servers: [$server->hostname]));
        $this->decisions->answerWith(DeploymentDecision::Continue);

        $this->runJob($deployment);

        $this->assertSame(DeploymentStatus::Done, $deployment->fresh()->status);
        $this->salt->assertRan(StepName::RsyncRemote, $server->hostname);
    }

    #[Test]
    public function force_skips_the_prompt_entirely_and_never_rolls_back(): void
    {
        $repository = Repository::factory()->create();
        $server = DeployTarget::factory()->create();

        $this->salt->alwaysRespondTo(StepName::Canary, false);

        $deployment = $this->deployment($repository, new DeploymentOptions(
            servers: [$server->hostname],
            force: true,
        ));

        $this->runJob($deployment);

        $deployment->refresh();

        // Nothing ever blocked, and the rollout happened despite both canaries.
        $this->assertNull($deployment->pending_decision);
        $this->salt->assertRan(StepName::RsyncRemote, $server->hostname);
        $this->assertSame(DeploymentStatus::Done, $deployment->status);
        $this->assertSame(0, Deployment::query()->whereNotNull('rolls_back_deployment_id')->count());
    }

    #[Test]
    public function an_unanswered_prompt_falls_back_to_the_configured_default(): void
    {
        config([
            'mwdeploy.decisions.timeout' => 1,
            'mwdeploy.decisions.timeout_default' => DeploymentDecision::AbortAndRollback->value,
        ]);

        $repository = Repository::factory()->create();
        DeployTarget::factory()->create();

        $this->salt
            ->alwaysRespondTo(StepName::GitHead, true, ['ref' => 'oldsha', 'ref_type' => 'commit'])
            ->alwaysRespondTo(StepName::Canary, false);

        $deployment = $this->deployment($repository);

        // Nobody answers; the gate's sleep() advances the test clock each time
        // round the poll loop until the timeout is reached.
        $this->runJob($deployment);

        $deployment->refresh();

        // The prompt itself is cleared once answered, so what proves the default
        // was applied is the outcome: aborted *and* rolled back.
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertStringContainsString('staging canary failed', (string) $deployment->failure_reason);
        $this->assertDatabaseHas('deployments', ['rolls_back_deployment_id' => $deployment->getKey()]);
        $this->assertSame(1, $this->decisions->promptCount());
    }

    #[Test]
    public function a_failed_appserver_canary_still_repools_that_server(): void
    {
        $repository = Repository::factory()->create();
        $server = DeployTarget::factory()->create(['hostname' => 'mw-us-east-011']);
        DeployTarget::factory()->proxy()->create(['hostname' => 'proxy-1']);

        // Staging passes, the appserver fails.
        $this->salt->respondTo(StepName::Canary, true, target: 'staging')
            ->alwaysRespondTo(StepName::Canary, false);

        $deployment = $this->deployment($repository, new DeploymentOptions(
            servers: [$server->hostname],
            rollout: true,
        ));
        $this->decisions->answerWith(DeploymentDecision::Abort);

        $this->runJob($deployment);

        // Leaving a depooled server out of the pool is its own outage, so the
        // repool must happen even on the abort path.
        $this->salt->assertRan(StepName::HaproxyRepool, 'proxy-1');
        $this->assertSame(DeploymentStatus::Aborted, $deployment->fresh()->status);
    }

    #[Test]
    public function a_failed_depool_stops_that_server_being_rsynced(): void
    {
        $repository = Repository::factory()->create();
        $server = DeployTarget::factory()->create();
        DeployTarget::factory()->proxy()->create();

        $this->salt->alwaysRespondTo(StepName::HaproxyDepool, false);

        $deployment = $this->deployment($repository, new DeploymentOptions(
            servers: [$server->hostname],
            rollout: true,
        ));

        $this->runJob($deployment);

        // Refusing to sync a box that is still taking traffic is the point of
        // the rollout flag.
        $this->salt->assertNeverRan(StepName::RsyncRemote);
        $this->assertSame(DeploymentStatus::Failed, $deployment->fresh()->status);
    }

    #[Test]
    public function aborting_mid_rollout_skips_the_servers_it_never_reached(): void
    {
        $repository = Repository::factory()->create();
        $first = DeployTarget::factory()->create(['hostname' => 'mw-01', 'sort_order' => 1]);
        $second = DeployTarget::factory()->create(['hostname' => 'mw-02', 'sort_order' => 2]);

        $this->salt->respondTo(StepName::Canary, true, target: 'staging')
            ->alwaysRespondTo(StepName::Canary, false);

        $deployment = $this->deployment($repository, new DeploymentOptions(
            servers: [$first->hostname, $second->hostname],
            parallel: 1,
        ));
        $this->decisions->answerWith(DeploymentDecision::Abort);

        $this->runJob($deployment);

        $this->salt->assertRan(StepName::RsyncRemote, 'mw-01');
        $this->salt->assertNeverRan(StepName::RsyncRemote, 'mw-02');

        // The server we never reached is recorded as skipped, not forgotten.
        $this->assertDatabaseHas('deployment_steps', [
            'deployment_id' => $deployment->getKey(),
            'target_hostname' => 'mw-02',
            'status' => StepStatus::Skipped->value,
        ]);
    }

    #[Test]
    public function parallelism_keeps_more_than_one_server_in_flight(): void
    {
        $repository = Repository::factory()->create();

        foreach (['mw-01', 'mw-02', 'mw-03'] as $index => $hostname) {
            DeployTarget::factory()->create(['hostname' => $hostname, 'sort_order' => $index]);
        }

        $deployment = $this->deployment($repository, new DeploymentOptions(parallel: 3));

        $this->runJob($deployment);

        $this->assertSame(DeploymentStatus::Done, $deployment->fresh()->status);
        $this->assertCount(3, $this->salt->callsFor(StepName::RsyncRemote));
    }

    #[Test]
    public function a_deployment_without_servers_targets_every_active_appserver(): void
    {
        $repository = Repository::factory()->create();
        DeployTarget::factory()->create(['hostname' => 'mw-01']);
        DeployTarget::factory()->create(['hostname' => 'mw-02']);
        DeployTarget::factory()->inactive()->create(['hostname' => 'mw-retired']);

        $deployment = $this->deployment($repository, new DeploymentOptions(servers: []));

        $this->runJob($deployment);

        $targets = array_map(fn (SaltCall $call) => $call->target, $this->salt->callsFor(StepName::RsyncRemote));

        sort($targets);

        $this->assertSame(['mw-01', 'mw-02'], $targets);
    }

    #[Test]
    public function staging_only_never_touches_an_appserver(): void
    {
        $repository = Repository::factory()->create();
        DeployTarget::factory()->create();

        $deployment = $this->deployment($repository, new DeploymentOptions(stagingOnly: true));

        $this->runJob($deployment);

        $this->assertSame(DeploymentStatus::Done, $deployment->fresh()->status);
        $this->salt->assertRan(StepName::RsyncLocal, 'staging');
        $this->salt->assertNeverRan(StepName::RsyncRemote);
    }

    #[Test]
    public function a_patch_failure_aborts_the_deployment_and_is_recorded(): void
    {
        $repository = Repository::factory()->create();
        DeployTarget::factory()->create();

        $patch = Patch::factory()->forRepository($repository)->create(['name' => 'T12345 hotfix']);

        $this->salt->alwaysRespondTo(StepName::PatchApply, false);

        $deployment = $this->deployment($repository, new DeploymentOptions(stagingOnly: true));
        $deployment->deploymentPatches()->create(['patch_id' => $patch->getKey(), 'applied' => false]);

        $this->runJob($deployment);

        $deployment->refresh();

        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertStringContainsString('T12345 hotfix', (string) $deployment->failure_reason);
        $this->salt->assertNeverRan(StepName::RsyncLocal);

        $this->assertDatabaseHas('deployment_patches', [
            'deployment_id' => $deployment->getKey(),
            'patch_id' => $patch->getKey(),
            'applied' => false,
        ]);
    }

    #[Test]
    public function a_successful_patch_records_the_ref_it_was_applied_against(): void
    {
        $repository = Repository::factory()->create();
        DeployTarget::factory()->create();

        $patch = Patch::factory()->forRepository($repository)->create();

        $deployment = $this->deployment($repository, new DeploymentOptions(stagingOnly: true));
        $deployment->deploymentPatches()->create(['patch_id' => $patch->getKey(), 'applied' => false]);

        $this->runJob($deployment);

        $this->assertDatabaseHas('deployment_patches', [
            'deployment_id' => $deployment->getKey(),
            'patch_id' => $patch->getKey(),
            'applied' => true,
            'applied_to_ref' => 'master',
        ]);
    }

    #[Test]
    public function an_extension_only_deployment_restricts_the_rsync_to_that_path(): void
    {
        $repository = Repository::factory()->create(['name' => 'Echo']);
        DeployTarget::factory()->create();

        $deployment = $this->deployment($repository, new DeploymentOptions(stagingOnly: true));

        $this->runJob($deployment);

        $command = $this->salt->callsFor(StepName::RsyncLocal)[0]->command->toString();

        $this->assertStringContainsString("'--path' 'versions/1.45/extensions/Echo'", $command);
    }

    #[Test]
    public function a_core_version_deployment_syncs_the_whole_tree(): void
    {
        $repository = Repository::factory()->core('1.46')->create();
        DeployTarget::factory()->create();

        $deployment = $this->deployment($repository, new DeploymentOptions(stagingOnly: true));

        $this->runJob($deployment);

        // A version bump touches too much to express as a path list.
        $this->assertStringNotContainsString(
            "'--path'",
            $this->salt->callsFor(StepName::RsyncLocal)[0]->command->toString(),
        );
    }

    #[Test]
    public function a_rollback_that_fails_its_canary_is_not_rolled_back_again(): void
    {
        $repository = Repository::factory()->create();
        DeployTarget::factory()->create();

        $original = $this->deployment($repository);
        $original->update(['status' => DeploymentStatus::Failed->value]);
        $original->snapshots()->create([
            'repository_id' => $repository->getKey(),
            'previous_ref_type' => RefType::Commit->value,
            'previous_ref_value' => 'oldsha',
            'new_ref_type' => RefType::Branch->value,
            'new_ref_value' => 'master',
        ]);

        $rollback = Deployment::factory()->create([
            'created_by' => $this->actor->getKey(),
            'rolls_back_deployment_id' => $original->getKey(),
            'options' => (new DeploymentOptions(stagingOnly: true))->toArray(),
        ]);
        $rollback->repoRefs()->create([
            'repository_id' => $repository->getKey(),
            'ref_type' => RefType::Commit->value,
            'ref_value' => 'oldsha',
        ]);

        $this->salt->alwaysRespondTo(StepName::Canary, false);
        $this->decisions->answerWith(DeploymentDecision::AbortAndRollback);

        $this->runJob($rollback);

        $rollback->refresh();

        // One automatic hop only: auto-rolling-back a rollback is how one bad
        // deploy becomes an outage.
        $this->assertSame(DeploymentStatus::Failed, $rollback->status);
        $this->assertStringContainsString('Manual intervention required', (string) $rollback->failure_reason);
        $this->assertSame(0, Deployment::query()->where('rolls_back_deployment_id', $rollback->getKey())->count());
    }

    #[Test]
    public function the_job_refuses_a_deployment_whose_creator_lacks_permission(): void
    {
        $repository = Repository::factory()->create();
        DeployTarget::factory()->create();

        $this->actor = $this->userWithPermissions(['deploy.skin']);

        $deployment = $this->deployment($repository);

        $this->runJob($deployment);

        $deployment->refresh();

        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertStringContainsString('Refused', (string) $deployment->failure_reason);
        $this->assertSame([], $this->salt->calls());
    }

    #[Test]
    public function the_job_refuses_a_force_deployment_from_someone_without_the_force_permission(): void
    {
        $repository = Repository::factory()->create();
        DeployTarget::factory()->create();

        $this->actor = $this->userWithPermissions([
            'deploy.extension', 'deploy.production_servers',
        ]);

        $deployment = $this->deployment($repository, new DeploymentOptions(force: true));

        $this->runJob($deployment);

        $this->assertStringContainsString('deploy.force_flag', (string) $deployment->fresh()->failure_reason);
        $this->assertSame([], $this->salt->calls());
    }

    #[Test]
    public function the_job_refuses_a_deployer_who_has_not_enrolled_two_factor(): void
    {
        $repository = Repository::factory()->create();
        DeployTarget::factory()->create();

        $this->actor = $this->userWithPermissions(
            ['deploy.extension', 'deploy.production_servers'],
            twoFactor: false,
        );

        $deployment = $this->deployment($repository);

        $this->runJob($deployment);

        $this->assertStringContainsString('two-factor', (string) $deployment->fresh()->failure_reason);
        $this->assertSame([], $this->salt->calls());
    }

    #[Test]
    public function a_deployment_that_is_not_pending_is_left_alone(): void
    {
        $repository = Repository::factory()->create();

        $deployment = $this->deployment($repository);
        $deployment->update(['status' => DeploymentStatus::Running->value]);

        $this->runJob($deployment);

        // Re-running a job for an in-flight deployment must not double-deploy.
        $this->assertSame([], $this->salt->calls());
        $this->assertSame(DeploymentStatus::Running, $deployment->fresh()->status);
    }

    #[Test]
    public function every_step_records_the_exact_salt_command_that_ran(): void
    {
        $repository = Repository::factory()->create();

        $deployment = $this->deployment($repository, new DeploymentOptions(stagingOnly: true));

        $this->runJob($deployment);

        $step = $deployment->fresh()->steps()->where('step_name', StepName::GitCheckout->value)->firstOrFail();

        $this->assertStringContainsString('--out=json', (string) $step->command);
        $this->assertStringContainsString('mwdeploy-shim', (string) $step->command);
        $this->assertStringContainsString('git-checkout', (string) $step->command);
    }

    private function deployment(Repository $repository, ?DeploymentOptions $options = null): Deployment
    {
        $deployment = Deployment::factory()->create([
            'created_by' => $this->actor->getKey(),
            'options' => ($options ?? new DeploymentOptions)->toArray(),
        ]);

        $deployment->repoRefs()->create([
            'repository_id' => $repository->getKey(),
            'ref_type' => RefType::Branch->value,
            'ref_value' => 'master',
        ]);

        return $deployment;
    }

    private function runJob(Deployment $deployment): void
    {
        (new RunDeployment($deployment->getKey()))->handle(
            app(DeploymentRunner::class),
            app(DeploymentAuthorizer::class),
        );
    }
}
