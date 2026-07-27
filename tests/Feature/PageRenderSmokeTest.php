<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DeploymentStatus;
use App\Enums\RefType;
use App\Enums\StepName;
use App\Enums\StepStatus;
use App\Models\Deployment;
use App\Models\DeployTarget;
use App\Models\Patch;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every read-only page, rendered once as an admin with representative data.
 *
 * A Blade typo in an admin screen is the kind of thing that only shows up at 2am
 * during an incident, so it is worth one cheap test per page.
 */
final class PageRenderSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Repository $repository;

    private Patch $patch;

    private Deployment $deployment;

    private DeployTarget $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeSalt();

        $this->admin = $this->admin();
        $this->repository = Repository::factory()->create(['name' => 'Echo']);
        $this->patch = Patch::factory()->forRepository($this->repository)->create([
            'last_check_ok' => false,
            'last_checked_at' => now()->subHour(),
            'last_check_detail' => 'Hunk #1 FAILED at 1.',
        ]);
        $this->server = DeployTarget::factory()->create(['hostname' => 'mw-us-east-011']);
        DeployTarget::factory()->proxy()->create(['hostname' => 'proxy-1']);
        DeployTarget::factory()->staging()->create();

        $this->deployment = $this->deploymentWithHistory();
    }

    /**
     * @return list<array{0: string}>
     */
    public static function readOnlyRoutes(): array
    {
        return [
            'dashboard' => ['dashboard'],
            'repository browser' => ['repositories.index'],
            'repository registration form' => ['repositories.create'],
            'deployment wizard' => ['deployments.create'],
            'deployment history' => ['deployments.index'],
            'patch registry' => ['patches.index'],
            'patch form' => ['patches.create'],
            'target inventory' => ['targets.index'],
            'users and roles' => ['users.index'],
            'two-factor setup' => ['two-factor.setup'],
        ];
    }

    #[Test]
    #[DataProvider('readOnlyRoutes')]
    public function it_renders(string $route): void
    {
        $this->actingAs($this->admin)->get(route($route))->assertOk();
    }

    #[Test]
    public function it_renders_a_repository(): void
    {
        $this->actingAs($this->admin)
            ->get(route('repositories.show', $this->repository))
            ->assertOk()
            ->assertSee('Echo');
    }

    #[Test]
    public function it_renders_the_repository_edit_form(): void
    {
        $this->actingAs($this->admin)
            ->get(route('repositories.edit', $this->repository))
            ->assertOk();
    }

    #[Test]
    public function it_renders_the_patch_edit_form(): void
    {
        $this->actingAs($this->admin)
            ->get(route('patches.edit', $this->patch))
            ->assertOk()
            ->assertSee($this->patch->name)
            ->assertSee($this->patch->target_path);
    }

    #[Test]
    public function the_patch_registry_surfaces_a_failed_dry_run(): void
    {
        $this->actingAs($this->admin)
            ->get(route('patches.index'))
            ->assertOk()
            ->assertSee('failed dry run')
            ->assertSee('Hunk #1 FAILED');
    }

    #[Test]
    public function it_renders_a_finished_deployment_with_all_its_panels(): void
    {
        $response = $this->actingAs($this->admin)->get(route('deployments.show', $this->deployment));

        $response->assertOk();
        $response->assertSee('mw-us-east-011');
        $response->assertSee('Undo point');
        // The staging panel is labelled distinctly from the per-server panels.
        $response->assertSee('Staging —');
        $response->assertSee('Roll back');
    }

    #[Test]
    public function it_renders_a_rollback_deployment_as_a_rollback(): void
    {
        $rollback = Deployment::factory()->status(DeploymentStatus::Done)->create([
            'created_by' => $this->admin->getKey(),
            'rolls_back_deployment_id' => $this->deployment->getKey(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('deployments.show', $rollback))
            ->assertOk()
            ->assertSee('Rollback of #'.$this->deployment->getKey());

        $this->actingAs($this->admin)
            ->get(route('deployments.index'))
            ->assertOk()
            ->assertSee('Rollback of #'.$this->deployment->getKey());
    }

    #[Test]
    public function it_renders_an_empty_estate_without_blowing_up(): void
    {
        // A freshly installed portal has no repositories, targets or history, and
        // every page still has to render so the operator can set them up.
        Deployment::query()->delete();
        Patch::query()->delete();
        Repository::query()->delete();
        DeployTarget::query()->delete();

        foreach (['dashboard', 'repositories.index', 'deployments.index', 'patches.index', 'targets.index'] as $route) {
            $this->actingAs($this->admin)->get(route($route))->assertOk();
        }
    }

    private function deploymentWithHistory(): Deployment
    {
        $deployment = Deployment::factory()->status(DeploymentStatus::Done)->create([
            'created_by' => $this->admin->getKey(),
            'started_at' => now()->subMinutes(4),
            'finished_at' => now(),
        ]);

        $deployment->repoRefs()->create([
            'repository_id' => $this->repository->getKey(),
            'ref_type' => RefType::Commit->value,
            'ref_value' => '4a9f2e1bd7c3a1f0e5b2c9d8a7f6e5d4c3b2a1f0',
        ]);

        $deployment->snapshots()->create([
            'repository_id' => $this->repository->getKey(),
            'previous_ref_type' => RefType::Branch->value,
            'previous_ref_value' => 'master',
            'new_ref_type' => RefType::Commit->value,
            'new_ref_value' => '4a9f2e1bd7c3a1f0e5b2c9d8a7f6e5d4c3b2a1f0',
        ]);

        $deployment->deploymentPatches()->create([
            'patch_id' => $this->patch->getKey(),
            'applied' => true,
            'applied_to_ref' => '4a9f2e1bd7c3a1f0e5b2c9d8a7f6e5d4c3b2a1f0',
        ]);

        $steps = [
            [(string) config('mwdeploy.targets.staging'), StepName::GitCheckout, StepStatus::Done],
            [(string) config('mwdeploy.targets.staging'), StepName::RsyncLocal, StepStatus::Done],
            [(string) config('mwdeploy.targets.staging'), StepName::Canary, StepStatus::Done],
            [$this->server->hostname, StepName::HaproxyDepool, StepStatus::Done],
            [$this->server->hostname, StepName::RsyncRemote, StepStatus::Done],
            [$this->server->hostname, StepName::Canary, StepStatus::Failed],
            [$this->server->hostname, StepName::HaproxyRepool, StepStatus::Done],
        ];

        foreach ($steps as $index => [$host, $step, $status]) {
            $deployment->steps()->create([
                'target_hostname' => $host,
                'step_name' => $step->value,
                'status' => $status->value,
                'sequence' => $index + 1,
                'command' => "salt --out=json --static '{$host}' cmd.run_all 'mwdeploy-shim {$step->value}'",
                'log' => "retcode=0\nstdout:\n{\"ok\": true}",
                'started_at' => now()->subMinutes(4 - $index),
                'finished_at' => now()->subMinutes(3 - $index),
            ]);
        }

        return $deployment->fresh();
    }
}
