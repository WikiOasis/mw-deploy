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
 * Every endpoint the single-page app calls, once, as an admin with representative
 * data — plus the pages that are still server-rendered.
 *
 * The screens are Vue now, so the useful assertion moved from "this Blade template
 * compiles" to "this payload contains what the screen needs". A missing field is the
 * same 2am bug the old render smoke test was catching.
 */
final class ApiSmokeTest extends TestCase
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

        // A second version, with one checkout removed, so the "undeployed" paths are
        // exercised too.
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
    public static function readOnlyEndpoints(): array
    {
        return [
            'bootstrap' => ['api.bootstrap'],
            'dashboard' => ['api.dashboard'],
            'versions' => ['api.versions.index'],
            'repositories' => ['api.repositories.index'],
            'config repository' => ['api.repositories.config'],
            'deployment history' => ['api.deployments.index'],
            'deploy wizard' => ['api.deployments.wizard'],
            'patches' => ['api.patches.index'],
            'targets' => ['api.targets.index'],
            'users' => ['api.users.index'],
        ];
    }

    #[Test]
    #[DataProvider('readOnlyEndpoints')]
    public function it_serves(string $route): void
    {
        $this->actingAs($this->admin)->getJson(route($route))->assertOk();
    }

    #[Test]
    public function the_spa_shell_renders_with_the_bootstrap_payload_inlined(): void
    {
        // The first paint has to know who is signed in and what they may do, or the
        // chrome renders and then changes shape once permissions arrive.
        $response = $this->actingAs($this->admin)->get('/');

        $response->assertOk();
        $response->assertSee('id="app"', escape: false);
        $response->assertSee('mwdeploy-bootstrap', escape: false);
        $response->assertSee($this->admin->email);
    }

    #[Test]
    public function the_shell_is_served_for_every_client_side_route(): void
    {
        $paths = ['/', '/deployments', '/deployments/new', '/versions', '/repositories', '/import', '/patches'];

        foreach ($paths as $path) {
            $this->actingAs($this->admin)->get($path)->assertOk()->assertSee('id="app"', escape: false);
        }
    }

    #[Test]
    public function the_catch_all_does_not_swallow_the_api_or_the_auth_pages(): void
    {
        // A catch-all that ate /login would make signing in impossible, which is a
        // bad way to find out about route ordering. Checked as a guest first,
        // because Fortify sends a signed-in user away from its own sign-in page.
        $this->get('/login')->assertOk()->assertSee('Sign in');

        $this->actingAs($this->admin)->getJson('/api/bootstrap')->assertJsonPath('authenticated', true);
        $this->actingAs($this->admin)->get(route('two-factor.setup'))->assertOk();
        $this->actingAs($this->admin)->get('/up')->assertOk();
    }

    #[Test]
    public function the_bootstrap_payload_reports_permissions_and_settings(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('api.bootstrap'))
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('can.manage_repositories', true)
            ->assertJsonPath('can.undeploy_version', true)
            ->assertJsonPath('settings.staging_host', (string) config('mwdeploy.targets.staging'))
            ->assertJsonPath('settings.config_dir', (string) config('mwdeploy.paths.config_dir'))
            ->assertJsonPath('counts.versions', 2);
    }

    #[Test]
    public function a_reader_is_told_what_they_may_not_do(): void
    {
        $reader = $this->userWithPermissions([]);

        $this->actingAs($reader)
            ->getJson(route('api.bootstrap'))
            ->assertOk()
            ->assertJsonPath('can.deploy', false)
            ->assertJsonPath('can.manage_repositories', false)
            ->assertJsonPath('can.manage_users', false);

        // …and the endpoints behind those abilities refuse independently. The SPA
        // hiding a link is a convenience, never the enforcement.
        $this->actingAs($reader)->getJson(route('api.targets.index'))->assertForbidden();
        $this->actingAs($reader)->getJson(route('api.users.index'))->assertForbidden();
        $this->actingAs($reader)->getJson(route('api.import.show'))->assertForbidden();
    }

    #[Test]
    public function a_version_reports_its_checkouts_and_whether_it_may_be_removed(): void
    {
        $response = $this->actingAs($this->admin)->getJson(route('api.versions.show', $this->v45));

        $response->assertOk();
        $response->assertJsonPath('data.version', '1.45');
        $response->assertJsonPath('data.path', 'versions/1.45');
        $response->assertJsonPath('data.can.undeploy', true);

        $names = array_column($response->json('data.checkouts'), 'repository_name');

        $this->assertContains('Echo', $names);
        $this->assertContains('mediawiki', $names);
    }

    #[Test]
    public function a_user_without_the_version_undeploy_permission_is_told_so(): void
    {
        $deployer = $this->userWithPermissions([
            Permissions::DEPLOY_CORE,
            Permissions::DEPLOY_PRODUCTION_SERVERS,
        ]);

        $this->actingAs($deployer)
            ->getJson(route('api.versions.show', $this->v45))
            ->assertOk()
            ->assertJsonPath('data.can.undeploy', false);
    }

    #[Test]
    public function a_repository_reports_one_row_per_checkout(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson(route('api.repositories.show', $this->echo->repository));

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Echo');

        $checkouts = collect($response->json('data.checkouts'));

        $this->assertSame(['1.46', '1.45'], $checkouts->pluck('version')->all());
        // 1.46 is registered but not on disk.
        $this->assertSame(['undeployed', 'present'], $checkouts->pluck('status')->all());
        $this->assertSame('pinned to REL1_45', $checkouts->firstWhere('version', '1.45')['ref_mode_summary']);
    }

    #[Test]
    public function the_patch_registry_surfaces_a_failed_dry_run(): void
    {
        $response = $this->actingAs($this->admin)->getJson(route('api.patches.index'));

        $response->assertOk();
        $response->assertJsonPath('data.0.last_check_ok', false);
        $response->assertJsonPath('data.0.last_check_detail', 'Hunk #1 FAILED at 1.');
        $response->assertJsonPath('data.0.target_label', $this->echo->displayName());
    }

    #[Test]
    public function a_finished_deployment_reports_its_steps_snapshots_and_patches(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson(route('api.deployments.show', $this->deployment));

        $response->assertOk();
        $response->assertJsonPath('data.status', 'done');
        $response->assertJsonPath('data.can.rollback', true);
        $response->assertJsonPath('data.staging_host', (string) config('mwdeploy.targets.staging'));

        $hosts = array_keys($response->json('data.steps_by_host'));

        $this->assertContains('mw-us-east-011', $hosts);
        $this->assertContains((string) config('mwdeploy.targets.staging'), $hosts);

        // The undo point is what makes a rollback possible in both directions.
        $response->assertJsonPath('data.snapshots.0.previous_ref', 'REL1_45');
        $response->assertJsonPath('data.snapshots.0.rollbackable', true);
        $response->assertJsonPath('data.patches.0.applied', true);
    }

    #[Test]
    public function the_state_endpoint_is_the_lean_payload_the_live_view_polls(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson(route('api.deployments.state', $this->deployment));

        $response->assertOk();
        $response->assertJsonPath('status', 'done');
        $response->assertJsonPath('terminal', true);
        $response->assertJsonPath('awaiting_decision', false);
        $response->assertJsonCount(7, 'steps');
        $response->assertJsonPath('steps.0.host', (string) config('mwdeploy.targets.staging'));
    }

    #[Test]
    public function an_undeploy_deployment_is_reported_distinctly(): void
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
            ->getJson(route('api.deployments.show', $deployment))
            ->assertOk()
            ->assertJsonPath('data.intent', 'undeploy')
            ->assertJsonPath('data.removes_anything', true)
            ->assertJsonPath('data.refs.0.action', 'undeploy');

        $this->actingAs($this->admin)
            ->getJson(route('api.deployments.index'))
            ->assertOk()
            ->assertJsonPath('data.0.intent_label', 'Undeploy');
    }

    #[Test]
    public function a_version_create_deployment_is_linked_to_its_version(): void
    {
        $deployment = Deployment::factory()
            ->intent(DeploymentIntent::VersionCreate)
            ->status(DeploymentStatus::Done)
            ->create([
                'created_by' => $this->admin->getKey(),
                'mediawiki_version_id' => $this->v45->getKey(),
            ]);

        $this->actingAs($this->admin)
            ->getJson(route('api.deployments.show', $deployment))
            ->assertOk()
            ->assertJsonPath('data.intent_label', 'Create core version')
            ->assertJsonPath('data.version', '1.45')
            ->assertJsonPath('data.summary', 'Created 1.45');
    }

    #[Test]
    public function a_rollback_is_reported_as_a_rollback(): void
    {
        $rollback = Deployment::factory()->status(DeploymentStatus::Done)->create([
            'created_by' => $this->admin->getKey(),
            'rolls_back_deployment_id' => $this->deployment->getKey(),
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('api.deployments.show', $rollback))
            ->assertOk()
            ->assertJsonPath('data.is_rollback', true)
            ->assertJsonPath('data.summary', 'Rollback of #'.$this->deployment->getKey());
    }

    #[Test]
    public function it_serves_an_empty_estate_without_blowing_up(): void
    {
        // A freshly installed portal has no versions, repositories, targets or
        // history, and every endpoint still has to answer so it can be set up.
        Deployment::query()->delete();
        Patch::query()->delete();
        RepositoryVersion::query()->delete();
        Repository::query()->delete();
        MediaWikiVersion::query()->delete();
        DeployTarget::query()->delete();

        foreach (self::readOnlyEndpoints() as [$route]) {
            $this->actingAs($this->admin)->getJson(route($route))->assertOk();
        }

        $this->actingAs($this->admin)
            ->getJson(route('api.dashboard'))
            ->assertJsonPath('registry.repositories', 0)
            ->assertJsonPath('registry.has_config_repository', false);
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
