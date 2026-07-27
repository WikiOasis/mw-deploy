<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Deployments\RollbackDeployment;
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
use App\Models\Patch;
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
 * Removing checkouts from staging and the fleet, and putting them back.
 */
final class UndeployTest extends TestCase
{
    use RefreshDatabase;

    private FakeSaltClient $salt;

    private AutoAnsweringDecisionGate $decisions;

    private User $actor;

    private MediaWikiVersion $v45;

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
    }

    #[Test]
    public function it_removes_the_checkout_from_staging_and_from_every_server(): void
    {
        $echo = $this->extension('Echo', $this->v45);
        DeployTarget::factory()->create(['hostname' => 'mw-01']);
        DeployTarget::factory()->create(['hostname' => 'mw-02']);

        $deployment = $this->undeployment([$echo], new DeploymentOptions(parallel: 2));

        $this->runJob($deployment);

        $this->assertSame(DeploymentStatus::Done, $deployment->fresh()->status);

        $removals = array_map(
            fn (SaltCall $call) => [$call->target, $call->command->toString()],
            $this->salt->callsFor(StepName::RepoRemove),
        );

        // Staging tree, the staging host's own production copy, then each server.
        $this->assertCount(4, $removals);

        $paths = array_map(fn (array $row) => $row[1], $removals);

        $this->assertStringContainsString(
            "'--path' '/srv/mediawiki-staging/versions/1.45/extensions/Echo' '--root' '/srv/mediawiki-staging'",
            $paths[0],
        );
        $this->assertStringContainsString(
            "'--path' '/srv/mediawiki/versions/1.45/extensions/Echo' '--root' '/srv/mediawiki'",
            $paths[1],
        );

        $this->salt->assertRan(StepName::RepoRemove, 'mw-01');
        $this->salt->assertRan(StepName::RepoRemove, 'mw-02');
    }

    #[Test]
    public function a_removal_never_passes_the_version_root_flag(): void
    {
        $echo = $this->extension('Echo', $this->v45);
        DeployTarget::factory()->create();

        $this->runJob($this->undeployment([$echo]));

        foreach ($this->salt->callsFor(StepName::RepoRemove) as $call) {
            // Only a whole-version removal may carry this, and the shim refuses a
            // bare versions/<ver> without it.
            $this->assertStringNotContainsString('allow-version-root', $call->command->toString());
        }
    }

    #[Test]
    public function a_removal_only_deployment_does_not_rsync_anything(): void
    {
        $echo = $this->extension('Echo', $this->v45);
        DeployTarget::factory()->create();

        $this->runJob($this->undeployment([$echo]));

        // "No paths" means "the whole tree" to rsync, so a removal-only deployment
        // must skip the rsync entirely rather than pass an empty path list.
        $this->salt->assertNeverRan(StepName::RsyncLocal);
        $this->salt->assertNeverRan(StepName::RsyncRemote);
    }

    #[Test]
    public function the_registry_records_the_checkout_as_undeployed_afterwards(): void
    {
        $echo = $this->extension('Echo', $this->v45);
        DeployTarget::factory()->create();

        $this->runJob($this->undeployment([$echo]));

        $echo->refresh();

        // The row survives so the checkout can be restored without registering the
        // repository again.
        $this->assertSame(PresenceStatus::Undeployed, $echo->status);
        $this->assertNotNull($echo->undeployed_at);
        $this->assertDatabaseHas('repository_versions', ['id' => $echo->getKey()]);
    }

    #[Test]
    public function a_failed_removal_leaves_the_registry_saying_it_is_still_deployed(): void
    {
        $echo = $this->extension('Echo', $this->v45);
        DeployTarget::factory()->create();

        $this->salt->alwaysRespondTo(StepName::RepoRemove, false);

        $deployment = $this->undeployment([$echo]);

        $this->runJob($deployment);

        $this->assertSame(DeploymentStatus::Failed, $deployment->fresh()->status);
        // Claiming it is gone when it is not would make the next rollback wrong.
        $this->assertSame(PresenceStatus::Present, $echo->fresh()->status);
    }

    #[Test]
    public function removing_one_version_leaves_the_others_alone(): void
    {
        $v46 = $this->version('1.46');
        $echo45 = $this->extension('Echo', $this->v45);
        $echo46 = $this->extension('Echo', $v46);

        DeployTarget::factory()->create();

        $this->runJob($this->undeployment([$echo46]));

        $this->assertSame(PresenceStatus::Present, $echo45->fresh()->status);
        $this->assertSame(PresenceStatus::Undeployed, $echo46->fresh()->status);

        foreach ($this->salt->callsFor(StepName::RepoRemove) as $call) {
            $this->assertStringNotContainsString('/versions/1.45/', $call->command->toString());
        }
    }

    #[Test]
    public function undoing_an_undeploy_restores_the_checkout_at_its_previous_ref(): void
    {
        $echo = $this->extension('Echo', $this->v45);
        DeployTarget::factory()->create();

        $this->salt->alwaysRespondTo(StepName::GitHead, true, ['ref' => 'REL1_45', 'ref_type' => 'branch']);

        $deployment = $this->undeployment([$echo]);
        $this->runJob($deployment);

        $this->assertSame(PresenceStatus::Undeployed, $echo->fresh()->status);

        // The undo point says it was present at REL1_45, so rolling back is a
        // deploy — the same three snapshot columns drive every direction.
        $rollback = app(RollbackDeployment::class)($deployment->fresh(), $this->actor, dispatch: false);

        $this->assertNotNull($rollback);
        $this->assertSame(DeploymentIntent::Deploy, $rollback->intent);

        $ref = $rollback->repoRefs()->firstOrFail();
        $this->assertSame(RepoAction::Deploy, $ref->action);
        $this->assertSame('REL1_45', $ref->ref_value);

        $this->runJob($rollback->fresh());

        $echo->refresh();
        $this->assertSame(PresenceStatus::Present, $echo->status);
        // It was not on disk, so it had to be cloned before being checked out.
        $this->salt->assertRan(StepName::RepoRegister);
    }

    #[Test]
    public function undoing_a_newly_added_checkout_removes_it_again(): void
    {
        $echo = RepositoryVersion::factory()
            ->of($this->extension('Thanks', $this->v45)->repository, $this->version('1.46'))
            ->undeployed()
            ->pinnedTo('master')
            ->create();

        DeployTarget::factory()->create();

        $deployment = $this->deployment([[$echo, 'master']]);
        $this->runJob($deployment);

        $this->assertSame(PresenceStatus::Present, $echo->fresh()->status);

        $rollback = app(RollbackDeployment::class)($deployment->fresh(), $this->actor, dispatch: false);

        // It was absent beforehand, so undoing the addition is a removal.
        $this->assertSame(DeploymentIntent::Undeploy, $rollback->intent);
        $this->assertSame(RepoAction::Undeploy, $rollback->repoRefs()->firstOrFail()->action);

        $this->runJob($rollback->fresh());

        $this->assertSame(PresenceStatus::Undeployed, $echo->fresh()->status);
    }

    #[Test]
    public function a_rollback_that_only_removes_things_carries_no_patches(): void
    {
        $echo = RepositoryVersion::factory()
            ->of($this->extension('Thanks', $this->v45)->repository, $this->version('1.47'))
            ->undeployed()
            ->create();

        $patch = Patch::factory()->forCheckout($echo)->create();

        DeployTarget::factory()->create();

        $deployment = $this->deployment([[$echo, 'master']]);
        $deployment->deploymentPatches()->create(['patch_id' => $patch->getKey(), 'applied' => false]);

        $this->runJob($deployment);

        $rollback = app(RollbackDeployment::class)($deployment->fresh(), $this->actor, dispatch: false);

        // There is nothing left to patch once the directory is gone.
        $this->assertSame(0, $rollback->deploymentPatches()->count());
    }

    #[Test]
    public function removals_happen_before_the_rsync_in_a_mixed_deployment(): void
    {
        $echo = $this->extension('Echo', $this->v45);
        $thanks = $this->extension('Thanks', $this->v45);

        DeployTarget::factory()->create(['hostname' => 'mw-01']);

        $deployment = Deployment::factory()->create([
            'created_by' => $this->actor->getKey(),
            'options' => (new DeploymentOptions)->toArray(),
        ]);

        DeploymentRepoRef::factory()->create([
            'deployment_id' => $deployment->getKey(),
            'repository_version_id' => $thanks->getKey(),
            'ref_value' => 'master',
        ]);
        DeploymentRepoRef::factory()->undeploy()->create([
            'deployment_id' => $deployment->getKey(),
            'repository_version_id' => $echo->getKey(),
        ]);

        $this->runJob($deployment->fresh());

        $sequence = $this->salt->stepSequence();
        $lastRemoval = array_keys($sequence, StepName::RepoRemove->value, true);
        $rsyncAt = array_search(StepName::RsyncRemote->value, $sequence, true);

        // Syncing a directory one step before deleting it is wasted work at best.
        $this->assertNotFalse($rsyncAt);
        $this->assertLessThan($rsyncAt, max($lastRemoval));

        // Only the surviving checkout is synced.
        $command = $this->salt->callsFor(StepName::RsyncRemote)[0]->command->toString();
        $this->assertStringContainsString('Thanks', $command);
        $this->assertStringNotContainsString('Echo', $command);
    }

    /**
     * @param  list<RepositoryVersion>  $checkouts
     */
    private function undeployment(array $checkouts, ?DeploymentOptions $options = null): Deployment
    {
        $deployment = Deployment::factory()->intent(DeploymentIntent::Undeploy)->create([
            'created_by' => $this->actor->getKey(),
            'options' => ($options ?? new DeploymentOptions)->toArray(),
        ]);

        foreach ($checkouts as $checkout) {
            DeploymentRepoRef::factory()->undeploy()->create([
                'deployment_id' => $deployment->getKey(),
                'repository_version_id' => $checkout->getKey(),
            ]);
        }

        return $deployment->fresh();
    }

    /**
     * @param  list<array{0: RepositoryVersion, 1: string}>  $items
     */
    private function deployment(array $items, ?DeploymentOptions $options = null): Deployment
    {
        $deployment = Deployment::factory()->create([
            'created_by' => $this->actor->getKey(),
            'options' => ($options ?? new DeploymentOptions)->toArray(),
        ]);

        foreach ($items as [$checkout, $ref]) {
            DeploymentRepoRef::factory()->create([
                'deployment_id' => $deployment->getKey(),
                'repository_version_id' => $checkout->getKey(),
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
