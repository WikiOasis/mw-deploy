<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Deployments\RollbackDeployment;
use App\Enums\DeploymentStatus;
use App\Enums\RefType;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\Patch;
use App\Models\Repository;
use App\Support\DeploymentOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Section 6: a rollback is just another deployment whose refs come from the
 * failed deployment's snapshots. There is no separate rollback pipeline.
 */
final class RollbackDeploymentTest extends TestCase
{
    use RefreshDatabase;

    private RollbackDeployment $rollback;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->rollback = app(RollbackDeployment::class);
    }

    #[Test]
    public function it_builds_refs_from_the_failed_deployments_snapshots(): void
    {
        $echo = Repository::factory()->create(['name' => 'Echo']);
        $vector = Repository::factory()->create(['name' => 'Vector']);

        $failed = $this->failedDeployment([
            [$echo, 'aaa111', RefType::Commit],
            [$vector, 'master', RefType::Branch],
        ]);

        $rollback = ($this->rollback)($failed);

        $this->assertNotNull($rollback);
        $this->assertSame($failed->getKey(), $rollback->rolls_back_deployment_id);
        $this->assertSame(DeploymentStatus::Pending, $rollback->status);

        $refs = $rollback->repoRefs()->get()->keyBy('repository_id');

        $this->assertSame('aaa111', $refs[$echo->getKey()]->ref_value);
        $this->assertSame(RefType::Commit, $refs[$echo->getKey()]->ref_type);
        $this->assertSame('master', $refs[$vector->getKey()]->ref_value);
        $this->assertSame(RefType::Branch, $refs[$vector->getKey()]->ref_type);
    }

    #[Test]
    public function it_rolls_back_every_repository_the_deployment_touched(): void
    {
        $echo = Repository::factory()->create();
        $vector = Repository::factory()->create();
        $core = Repository::factory()->core('1.45')->create();

        $failed = $this->failedDeployment([
            [$echo, 'aaa', RefType::Commit],
            [$vector, 'bbb', RefType::Commit],
            [$core, 'ccc', RefType::Commit],
        ]);

        $rollback = ($this->rollback)($failed);

        // Rolling back an extension but leaving core on the new version would
        // leave staging internally inconsistent.
        $this->assertSame(3, $rollback->repoRefs()->count());
    }

    #[Test]
    public function it_refuses_when_no_usable_undo_point_was_recorded(): void
    {
        $repository = Repository::factory()->create();

        $failed = Deployment::factory()->status(DeploymentStatus::Failed)->create();
        $failed->snapshots()->create([
            'repository_id' => $repository->getKey(),
            'previous_ref_type' => null,
            'previous_ref_value' => null,
            'new_ref_type' => RefType::Branch->value,
            'new_ref_value' => 'master',
        ]);

        $this->assertNull(($this->rollback)($failed));
        $this->assertSame(0, Deployment::query()->whereNotNull('rolls_back_deployment_id')->count());
    }

    #[Test]
    public function it_skips_repositories_whose_head_could_not_be_read(): void
    {
        $readable = Repository::factory()->create();
        $unreadable = Repository::factory()->create();

        $failed = $this->failedDeployment([[$readable, 'aaa', RefType::Commit]]);
        $failed->snapshots()->create([
            'repository_id' => $unreadable->getKey(),
            'previous_ref_type' => null,
            'previous_ref_value' => null,
            'new_ref_type' => RefType::Branch->value,
            'new_ref_value' => 'master',
        ]);

        $rollback = ($this->rollback)($failed);

        $this->assertSame(1, $rollback->repoRefs()->count());
        $this->assertSame($readable->getKey(), $rollback->repoRefs()->first()->repository_id);
    }

    #[Test]
    public function it_reuses_the_original_rollout_options_but_never_force(): void
    {
        $repository = Repository::factory()->create();

        $failed = $this->failedDeployment(
            [[$repository, 'aaa', RefType::Commit]],
            new DeploymentOptions(servers: ['mw-01', 'mw-02'], parallel: 4, force: true, l10n: true, rollout: true),
        );

        $options = ($this->rollback)($failed)->opts();

        $this->assertSame(4, $options->parallel);
        $this->assertTrue($options->rollout);
        $this->assertTrue($options->l10n);
        $this->assertSame(['mw-01', 'mw-02'], $options->servers);

        // A rollback must not skip its own canary, whatever the forward deploy did.
        $this->assertFalse($options->force);
    }

    #[Test]
    public function an_automatic_rollback_is_scoped_to_the_servers_that_were_touched(): void
    {
        $repository = Repository::factory()->create();

        $failed = $this->failedDeployment(
            [[$repository, 'aaa', RefType::Commit]],
            new DeploymentOptions(servers: ['mw-01', 'mw-02', 'mw-03']),
        );

        // Servers the failed deployment never reached are still on the previous
        // ref and were never at risk.
        $rollback = ($this->rollback)($failed, servers: ['mw-01']);

        $this->assertSame(['mw-01'], $rollback->opts()->servers);
    }

    #[Test]
    public function it_carries_forward_patches_that_were_actually_applied(): void
    {
        $repository = Repository::factory()->create();
        $applied = Patch::factory()->forRepository($repository)->create();
        $skipped = Patch::factory()->forRepository($repository)->create();

        $failed = $this->failedDeployment([[$repository, 'aaa', RefType::Commit]]);
        $failed->deploymentPatches()->create(['patch_id' => $applied->getKey(), 'applied' => true]);
        $failed->deploymentPatches()->create(['patch_id' => $skipped->getKey(), 'applied' => false]);

        $rollback = ($this->rollback)($failed);

        // The previous ref is what the patch was validated against, so dropping
        // it on the way back would silently un-patch the farm.
        $this->assertSame([$applied->getKey()], $rollback->deploymentPatches()->pluck('patch_id')->all());
    }

    #[Test]
    public function it_queues_the_same_job_class_as_a_forward_deploy(): void
    {
        $repository = Repository::factory()->create();
        $failed = $this->failedDeployment([[$repository, 'aaa', RefType::Commit]]);

        $rollback = ($this->rollback)($failed);

        Queue::assertPushed(
            RunDeployment::class,
            fn (RunDeployment $job) => $job->deploymentId === $rollback->getKey(),
        );
    }

    /**
     * @param  list<array{0: Repository, 1: string, 2: RefType}>  $snapshots
     */
    private function failedDeployment(array $snapshots, ?DeploymentOptions $options = null): Deployment
    {
        $deployment = Deployment::factory()
            ->status(DeploymentStatus::Failed)
            ->withOptions($options ?? new DeploymentOptions(servers: ['mw-01']))
            ->create(['created_by' => $this->admin()->getKey()]);

        foreach ($snapshots as [$repository, $previous, $type]) {
            $deployment->repoRefs()->create([
                'repository_id' => $repository->getKey(),
                'ref_type' => RefType::Branch->value,
                'ref_value' => 'master',
            ]);

            $deployment->snapshots()->create([
                'repository_id' => $repository->getKey(),
                'previous_ref_type' => $type->value,
                'previous_ref_value' => $previous,
                'new_ref_type' => RefType::Branch->value,
                'new_ref_value' => 'master',
            ]);
        }

        return $deployment;
    }
}
