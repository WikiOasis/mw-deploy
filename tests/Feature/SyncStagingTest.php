<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DeploymentDecision;
use App\Enums\DeploymentIntent;
use App\Enums\DeploymentStatus;
use App\Enums\StepName;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\DeployTarget;
use App\Models\User;
use App\Services\Deployment\DeploymentAuthorizer;
use App\Services\Deployment\DeploymentRunner;
use App\Services\Salt\Testing\FakeSaltClient;
use App\Support\DeploymentOptions;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AutoAnsweringDecisionGate;
use Tests\TestCase;

/**
 * Deploying the staging tree exactly as it stands: no fetch, no checkout, nothing
 * selected.
 *
 * The escape hatch for work that never came from a ref — a file edited directly on
 * staging, a patch applied by hand — so the interesting assertions are all about
 * what it does *not* do to the tree on the way out.
 */
final class SyncStagingTest extends TestCase
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
            'mwdeploy.decisions.timeout' => 0,
        ]);

        Queue::fake();

        $this->salt = $this->fakeSalt();
        $this->decisions = $this->fakeDecisions();
        $this->actor = $this->admin();
    }

    #[Test]
    public function it_syncs_the_whole_tree_to_production_and_every_appserver(): void
    {
        DeployTarget::factory()->create(['hostname' => 'mw-01']);
        DeployTarget::factory()->create(['hostname' => 'mw-02']);

        $deployment = $this->syncDeployment(new DeploymentOptions(parallel: 2));

        $this->runJob($deployment);

        $this->assertSame(DeploymentStatus::Done, $deployment->fresh()->status);

        $local = $this->salt->callsFor(StepName::RsyncLocal);

        $this->assertCount(1, $local);
        $this->assertStringContainsString("'--src' '/srv/mediawiki-staging/'", $local[0]->command->toString());
        $this->assertStringContainsString("'--dst' '/srv/mediawiki/'", $local[0]->command->toString());

        // No --path anywhere: an empty path list is how both rsync verbs are told
        // "the whole tree", and a staging sync is the intent that means it.
        foreach ([...$local, ...$this->salt->callsFor(StepName::RsyncRemote)] as $call) {
            $this->assertStringNotContainsString("'--path'", $call->command->toString());
        }

        $this->salt->assertRan(StepName::RsyncRemote, 'mw-01');
        $this->salt->assertRan(StepName::RsyncRemote, 'mw-02');
    }

    #[Test]
    public function it_never_touches_git_on_the_way_out(): void
    {
        DeployTarget::factory()->create();

        // Present checkouts exist; the point is that a sync ignores them rather
        // than fetching or resetting anything.
        $this->extension('Echo', $this->version('1.45'));

        $this->runJob($this->syncDeployment());

        $this->salt->assertNeverRan(StepName::GitFetch);
        $this->salt->assertNeverRan(StepName::GitPull);
        $this->salt->assertNeverRan(StepName::GitCheckout);
        $this->salt->assertNeverRan(StepName::GitHead);
        $this->salt->assertNeverRan(StepName::RepoRegister);
        $this->salt->assertNeverRan(StepName::RepoRemove);
        $this->salt->assertNeverRan(StepName::PatchApply);
    }

    #[Test]
    public function the_staging_canary_still_gates_the_rollout(): void
    {
        DeployTarget::factory()->create(['hostname' => 'mw-01']);

        $this->salt->alwaysRespondTo(StepName::Canary, false);
        // The gate genuinely blocks on a real operator; this is one clicking abort.
        $this->decisions->answerWith(DeploymentDecision::Abort);

        $deployment = $this->syncDeployment();

        $this->runJob($deployment);

        $this->assertSame(DeploymentStatus::Failed, $deployment->fresh()->status);
        $this->assertStringContainsString('staging canary failed', (string) $deployment->fresh()->failure_reason);
        // Never reached an appserver: the gate is the same one every other intent
        // passes through.
        $this->salt->assertNeverRan(StepName::RsyncRemote);
    }

    #[Test]
    public function a_staging_only_sync_updates_the_production_tree_on_staging_and_stops(): void
    {
        DeployTarget::factory()->create(['hostname' => 'mw-01']);

        $deployment = $this->syncDeployment(new DeploymentOptions(stagingOnly: true));

        $this->runJob($deployment);

        $this->assertSame(DeploymentStatus::Done, $deployment->fresh()->status);
        $this->salt->assertRan(StepName::RsyncLocal, 'staging');
        $this->salt->assertNeverRan(StepName::RsyncRemote);
    }

    #[Test]
    public function a_failed_sync_enqueues_no_rollback_because_staging_was_never_mutated(): void
    {
        DeployTarget::factory()->create(['hostname' => 'mw-01']);

        $this->salt->alwaysRespondTo(StepName::RsyncLocal, false);

        $deployment = $this->syncDeployment();

        $this->runJob($deployment);

        $this->assertSame(DeploymentStatus::Failed, $deployment->fresh()->status);
        // There is no undo point to roll back to — nothing about the staging tree
        // was changed by this deployment, so the tree it shipped is still there.
        $this->assertSame(1, Deployment::query()->count());
    }

    #[Test]
    public function the_history_row_says_which_tree_was_shipped(): void
    {
        $deployment = $this->syncDeployment();

        $this->assertSame('Synced /srv/mediawiki-staging as it stood', $deployment->summary());
    }

    /*
     * ----------------------------------------------------------------------- *
     * The wizard endpoints
     * ----------------------------------------------------------------------- *
     */

    #[Test]
    public function the_wizard_offers_no_selection_and_names_both_trees(): void
    {
        $response = $this->actingAs($this->syncer())
            ->getJson(route('api.deployments.wizard', ['intent' => 'sync_staging']));

        $response->assertOk();

        $this->assertSame('sync_staging', $response->json('intent'));
        $this->assertSame([], $response->json('repositories'));
        $this->assertSame([], $response->json('patches'));
        $this->assertSame('/srv/mediawiki-staging', $response->json('tree.staging'));
        $this->assertSame('/srv/mediawiki', $response->json('tree.production'));
    }

    #[Test]
    public function the_plan_is_an_rsync_of_the_whole_tree_with_no_preparation_steps(): void
    {
        DeployTarget::factory()->create(['hostname' => 'mw-01']);
        $this->extension('Echo', $this->version('1.45'));

        $response = $this->actingAs($this->syncer())
            ->postJson(route('api.deployments.plan'), [
                'intent' => 'sync_staging',
                'items' => [],
                'parallel' => 1,
            ]);

        $response->assertOk();

        $steps = collect($response->json('phases'))->flatten(1)->pluck('step')->all();

        $this->assertSame([
            StepName::RsyncLocal->value,
            StepName::Canary->value,
            StepName::RsyncRemote->value,
            StepName::Canary->value,
        ], $steps);

        $this->assertSame([], $response->json('items'));
        $this->assertFalse($response->json('removes_anything'));
    }

    #[Test]
    public function submitting_it_stores_a_deployment_with_no_line_items(): void
    {
        DeployTarget::factory()->create(['hostname' => 'mw-01']);

        $this->actingAs($this->syncer())
            ->postJson(route('api.deployments.store'), [
                'intent' => 'sync_staging',
                'items' => [],
                'parallel' => 1,
            ])
            ->assertCreated();

        $deployment = Deployment::query()->latest('id')->firstOrFail();

        $this->assertSame(DeploymentIntent::SyncStaging, $deployment->intent);
        $this->assertSame(0, $deployment->repoRefs()->count());

        Queue::assertPushed(RunDeployment::class);
    }

    #[Test]
    public function selecting_checkouts_under_a_sync_is_refused_rather_than_ignored(): void
    {
        $echo = $this->extension('Echo', $this->version('1.45'));

        $this->actingAs($this->syncer())
            ->postJson(route('api.deployments.store'), [
                'intent' => 'sync_staging',
                'items' => [
                    ['repository_version_id' => $echo->getKey(), 'ref_value' => 'REL1_45'],
                ],
                'parallel' => 1,
            ])
            ->assertJsonValidationErrors('items');

        $this->assertSame(0, Deployment::query()->count());
    }

    /*
     * ----------------------------------------------------------------------- *
     * Permissions
     * ----------------------------------------------------------------------- *
     */

    #[Test]
    public function every_deploy_permission_short_of_the_sync_grant_is_not_enough(): void
    {
        $deployer = $this->userWithPermissions([
            Permissions::DEPLOY_CORE,
            Permissions::DEPLOY_EXTENSION,
            Permissions::DEPLOY_SKIN,
            Permissions::DEPLOY_CONFIG,
            Permissions::DEPLOY_PRODUCTION_SERVERS,
        ]);

        $this->actingAs($deployer)
            ->getJson(route('api.deployments.wizard', ['intent' => 'sync_staging']))
            ->assertForbidden();

        $this->actingAs($deployer)
            ->postJson(route('api.deployments.store'), [
                'intent' => 'sync_staging',
                'items' => [],
                'parallel' => 1,
            ])
            ->assertForbidden();

        $this->assertSame(0, Deployment::query()->count());
    }

    #[Test]
    public function the_job_refuses_a_sync_whose_creator_lacks_the_grant(): void
    {
        DeployTarget::factory()->create(['hostname' => 'mw-01']);

        $deployment = Deployment::factory()->intent(DeploymentIntent::SyncStaging)->create([
            'created_by' => $this->userWithPermissions([
                Permissions::DEPLOY_EXTENSION,
                Permissions::DEPLOY_PRODUCTION_SERVERS,
            ])->getKey(),
            'options' => (new DeploymentOptions)->toArray(),
        ]);

        $this->runJob($deployment->fresh());

        $this->assertSame(DeploymentStatus::Failed, $deployment->fresh()->status);
        $this->assertStringContainsString(Permissions::DEPLOY_SYNC_STAGING, (string) $deployment->fresh()->failure_reason);
        // Refused before anything left the portal.
        $this->salt->assertNeverRan(StepName::RsyncLocal);
    }

    private function syncer(): User
    {
        return $this->userWithPermissions([
            Permissions::DEPLOY_SYNC_STAGING,
            Permissions::DEPLOY_PRODUCTION_SERVERS,
        ]);
    }

    private function syncDeployment(?DeploymentOptions $options = null): Deployment
    {
        return Deployment::factory()->intent(DeploymentIntent::SyncStaging)->create([
            'created_by' => $this->actor->getKey(),
            'options' => ($options ?? new DeploymentOptions)->toArray(),
        ])->fresh();
    }

    private function runJob(Deployment $deployment): void
    {
        (new RunDeployment($deployment->getKey()))->handle(
            app(DeploymentRunner::class),
            app(DeploymentAuthorizer::class),
        );
    }
}
