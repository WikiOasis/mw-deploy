<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DeploymentIntent;
use App\Enums\DeploymentStatus;
use App\Enums\RefType;
use App\Enums\StepName;
use App\Enums\StepStatus;
use App\Models\Deployment;
use App\Models\DeploymentRepoRef;
use App\Models\DeployTarget;
use App\Models\MediaWikiVersion;
use App\Models\Patch;
use App\Models\Repository;
use App\Models\RepositoryVersion;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every page, rendered once as an admin with representative data.
 *
 * A Blade typo in an admin screen is the kind of thing that only shows up at 2am
 * during an incident, so it is worth one cheap test per page.
 */
final class PageRenderSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MediaWikiVersion $v45;

    private RepositoryVersion $echo;

    private Patch $patch;

    private Deployment $deployment;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->fakeSalt();

        $this->admin = $this->admin();

        $this->v45 = $this->version('1.45');
        $this->core($this->v45, 'REL1_45');
        $this->echo = $this->extension('Echo', $this->v45, 'REL1_45');

        // A second version, with one checkout removed, so the "undeployed" paths
        // render too.
        $v46 = $this->version('1.46');
        $this->core($v46, 'REL1_46');
        RepositoryVersion::factory()->of($this->echo->repository, $v46)->undeployed()->create();

        $this->patch = Patch::factory()->forCheckout($this->echo)->create([
            'last_check_ok' => false,
            'last_checked_at' => now()->subHour(),
            'last_check_detail' => 'Hunk #1 FAILED at 1.',
        ]);

        DeployTarget::factory()->create(['hostname' => 'mw-us-east-011']);
        DeployTarget::factory()->proxy()->create(['hostname' => 'proxy-1']);
        DeployTarget::factory()->staging()->create();

        $this->deployment = $this->deploymentWithHistory();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function readOnlyRoutes(): array
    {
        return [
            'dashboard' => ['dashboard'],
            'versions' => ['versions.index'],
            'repository browser' => ['repositories.index'],
            'repository registration form' => ['repositories.create'],
            'deploy wizard' => ['deployments.create'],
            'undeploy wizard' => ['deployments.undeploy'],
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
    public function it_renders_a_version_with_its_checkouts_and_the_removal_form(): void
    {
        $response = $this->actingAs($this->admin)->get(route('versions.show', $this->v45));

        $response->assertOk();
        $response->assertSee('Echo');
        $response->assertSee('versions/1.45');
        // The removal form is gated and must warn, not just offer a button.
        $response->assertSee('Undeploy this version');
        $response->assertSee('Type the version to confirm');
    }

    #[Test]
    public function a_user_without_the_version_undeploy_permission_sees_no_removal_form(): void
    {
        $deployer = $this->userWithPermissions([
            Permissions::DEPLOY_CORE,
            Permissions::DEPLOY_PRODUCTION_SERVERS,
        ]);

        $this->actingAs($deployer)
            ->get(route('versions.show', $this->v45))
            ->assertOk()
            ->assertDontSee('Undeploy this version');
    }

    #[Test]
    public function it_renders_a_repository_with_one_row_per_checkout(): void
    {
        $response = $this->actingAs($this->admin)->get(route('repositories.show', $this->echo->repository));

        $response->assertOk();
        $response->assertSee('Echo');
        $response->assertSee('1.45');
        $response->assertSee('1.46');
        // 1.46 is registered but not on disk.
        $response->assertSee('Undeployed');
        $response->assertSee('pinned to REL1_45');
    }

    #[Test]
    public function it_renders_the_repository_and_patch_edit_forms(): void
    {
        $this->actingAs($this->admin)
            ->get(route('repositories.edit', $this->echo->repository))
            ->assertOk()
            ->assertSee('versions/1.45/extensions/Echo');

        $this->actingAs($this->admin)
            ->get(route('patches.edit', $this->patch))
            ->assertOk()
            ->assertSee($this->patch->name);
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
        $response->assertSee('Staging —');
        $response->assertSee('Roll back');
    }

    #[Test]
    public function it_renders_an_undeploy_deployment_distinctly(): void
    {
        $deployment = Deployment::factory()
            ->intent(DeploymentIntent::Undeploy)
            ->status(DeploymentStatus::Done)
            ->create(['created_by' => $this->admin->getKey()]);

        DeploymentRepoRef::factory()->undeploy()->create([
            'deployment_id' => $deployment->getKey(),
            'repository_version_id' => $this->echo->getKey(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('deployments.show', $deployment))
            ->assertOk()
            ->assertSee('Undeploy')
            ->assertSee('removed');

        $this->actingAs($this->admin)
            ->get(route('deployments.index'))
            ->assertOk()
            ->assertSee('Undeploy');
    }

    #[Test]
    public function it_renders_a_version_create_deployment_linked_to_its_version(): void
    {
        $deployment = Deployment::factory()
            ->intent(DeploymentIntent::VersionCreate)
            ->status(DeploymentStatus::Done)
            ->create([
                'created_by' => $this->admin->getKey(),
                'mediawiki_version_id' => $this->v45->getKey(),
            ]);

        $this->actingAs($this->admin)
            ->get(route('deployments.show', $deployment))
            ->assertOk()
            ->assertSee('Create core version')
            ->assertSee('1.45');
    }

    #[Test]
    public function it_renders_a_rollback_as_a_rollback(): void
    {
        $rollback = Deployment::factory()->status(DeploymentStatus::Done)->create([
            'created_by' => $this->admin->getKey(),
            'rolls_back_deployment_id' => $this->deployment->getKey(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('deployments.show', $rollback))
            ->assertOk()
            ->assertSee('Rollback of #'.$this->deployment->getKey());
    }

    #[Test]
    public function it_renders_an_empty_estate_without_blowing_up(): void
    {
        // A freshly installed portal has no versions, repositories, targets or
        // history, and every page still has to render so it can be set up.
        Deployment::query()->delete();
        Patch::query()->delete();
        RepositoryVersion::query()->delete();
        Repository::query()->delete();
        MediaWikiVersion::query()->delete();
        DeployTarget::query()->delete();

        foreach (self::readOnlyRoutes() as [$route]) {
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

        DeploymentRepoRef::factory()->commit('4a9f2e1bd7c3a1f0e5b2c9d8a7f6e5d4c3b2a1f0')->create([
            'deployment_id' => $deployment->getKey(),
            'repository_version_id' => $this->echo->getKey(),
        ]);

        $deployment->snapshots()->create([
            'repository_version_id' => $this->echo->getKey(),
            'previous_present' => true,
            'previous_ref_type' => RefType::Branch->value,
            'previous_ref_value' => 'REL1_45',
            'new_present' => true,
            'new_ref_type' => RefType::Commit->value,
            'new_ref_value' => '4a9f2e1bd7c3a1f0e5b2c9d8a7f6e5d4c3b2a1f0',
        ]);

        $deployment->deploymentPatches()->create([
            'patch_id' => $this->patch->getKey(),
            'applied' => true,
            'applied_to_ref' => 'REL1_45',
        ]);

        $staging = (string) config('mwdeploy.targets.staging');

        $steps = [
            [$staging, StepName::GitCheckout, StepStatus::Done],
            [$staging, StepName::RsyncLocal, StepStatus::Done],
            [$staging, StepName::Canary, StepStatus::Done],
            ['mw-us-east-011', StepName::HaproxyDepool, StepStatus::Done],
            ['mw-us-east-011', StepName::RsyncRemote, StepStatus::Done],
            ['mw-us-east-011', StepName::Canary, StepStatus::Failed],
            ['mw-us-east-011', StepName::HaproxyRepool, StepStatus::Done],
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
