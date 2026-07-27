<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RefType;
use App\Enums\RepositoryType;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\DeployTarget;
use App\Models\Patch;
use App\Models\Repository;
use App\Models\RepositoryPermission;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Section 4.2 plus the UI half of section 3.5.3. Every permission is checked in
 * two places; these are the UI-side checks.
 */
final class DeploymentWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->fakeSalt();

        DeployTarget::factory()->create(['hostname' => 'mw-01']);
    }

    #[Test]
    public function a_reader_cannot_open_the_wizard(): void
    {
        $this->actingAs($this->userWithPermissions([]))
            ->get(route('deployments.create'))
            ->assertForbidden();
    }

    #[Test]
    public function a_deployer_sees_only_the_repositories_they_may_deploy(): void
    {
        $extension = Repository::factory()->create(['name' => 'Echo']);
        $core = Repository::factory()->core('1.46')->create();

        $response = $this->actingAs($this->deployer([Permissions::DEPLOY_EXTENSION]))
            ->get(route('deployments.create'));

        $response->assertOk();
        $response->assertSee($extension->name);
        // An extension maintainer must not be able to slip a core version bump
        // through the same form.
        $response->assertDontSee('versions/1.46');
    }

    #[Test]
    public function it_creates_a_deployment_and_queues_the_job(): void
    {
        $repository = Repository::factory()->create();
        $user = $this->deployer();

        $response = $this->actingAs($user)->post(route('deployments.store'), [
            'refs' => [['repository_id' => $repository->getKey(), 'ref_type' => 'branch', 'ref_value' => 'master']],
            'parallel' => 1,
            'rollout' => '1',
        ]);

        $deployment = Deployment::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('deployments.show', $deployment));

        $this->assertSame($user->getKey(), $deployment->created_by);
        $this->assertTrue($deployment->opts()->rollout);
        $this->assertSame('master', $deployment->repoRefs()->first()->ref_value);

        Queue::assertPushed(RunDeployment::class);
    }

    #[Test]
    public function a_sha_typed_into_the_branch_field_is_still_stored_as_a_commit(): void
    {
        $repository = Repository::factory()->create();

        $this->actingAs($this->deployer())->post(route('deployments.store'), [
            'refs' => [[
                'repository_id' => $repository->getKey(),
                'ref_type' => 'branch',
                'ref_value' => '4a9f2e1bd7c3a1f0e5b2c9d8a7f6e5d4c3b2a1f0',
            ]],
            'parallel' => 1,
        ]);

        $ref = Deployment::query()->latest('id')->firstOrFail()->repoRefs()->firstOrFail();

        $this->assertSame(RefType::Commit, $ref->ref_type);
    }

    #[Test]
    public function it_rejects_a_ref_containing_shell_metacharacters(): void
    {
        $repository = Repository::factory()->create();

        $this->actingAs($this->deployer())
            ->post(route('deployments.store'), [
                'refs' => [[
                    'repository_id' => $repository->getKey(),
                    'ref_type' => 'branch',
                    'ref_value' => 'master; rm -rf /srv',
                ]],
                'parallel' => 1,
            ])
            ->assertSessionHasErrors('refs.0.ref_value');

        $this->assertSame(0, Deployment::query()->count());
    }

    #[Test]
    public function it_rejects_a_repository_the_user_may_not_deploy(): void
    {
        $skin = Repository::factory()->ofType(RepositoryType::Skin)->create();

        $this->actingAs($this->deployer([Permissions::DEPLOY_EXTENSION]))
            ->post(route('deployments.store'), [
                'refs' => [['repository_id' => $skin->getKey(), 'ref_type' => 'branch', 'ref_value' => 'master']],
                'parallel' => 1,
            ])
            ->assertSessionHasErrors('refs.0.repository_id');

        $this->assertSame(0, Deployment::query()->count());
    }

    #[Test]
    public function per_repository_scoping_narrows_a_maintainer_to_their_own_extensions(): void
    {
        $mine = Repository::factory()->create(['name' => 'Echo']);
        $theirs = Repository::factory()->create(['name' => 'Thanks']);

        $user = $this->deployer([Permissions::DEPLOY_EXTENSION]);

        // Scoping only 'mine' must not lock the user out of unscoped repos.
        RepositoryPermission::query()->create([
            'repository_id' => $mine->getKey(),
            'user_id' => $user->getKey(),
        ]);

        $this->assertTrue($user->canDeployRepository($mine));
        $this->assertTrue($user->canDeployRepository($theirs));

        // Once 'theirs' is scoped to somebody else, this user loses it.
        RepositoryPermission::query()->create([
            'repository_id' => $theirs->getKey(),
            'user_id' => User::factory()->create()->getKey(),
        ]);

        $this->assertTrue($user->fresh()->canDeployRepository($mine));
        $this->assertFalse($user->fresh()->canDeployRepository($theirs));
    }

    #[Test]
    public function only_an_account_with_the_force_permission_may_set_force(): void
    {
        $repository = Repository::factory()->create();

        $payload = [
            'refs' => [['repository_id' => $repository->getKey(), 'ref_type' => 'branch', 'ref_value' => 'master']],
            'parallel' => 1,
            'force' => '1',
        ];

        $this->actingAs($this->deployer())
            ->post(route('deployments.store'), $payload)
            ->assertSessionHasErrors('force');

        $this->actingAs($this->admin())
            ->post(route('deployments.store'), $payload)
            ->assertSessionHasNoErrors();

        $this->assertTrue(Deployment::query()->latest('id')->firstOrFail()->opts()->force);
    }

    #[Test]
    public function a_staging_only_user_cannot_target_production(): void
    {
        $repository = Repository::factory()->create();

        $stagingOnly = $this->userWithPermissions([Permissions::DEPLOY_EXTENSION]);

        $payload = [
            'refs' => [['repository_id' => $repository->getKey(), 'ref_type' => 'branch', 'ref_value' => 'master']],
            'parallel' => 1,
        ];

        $this->actingAs($stagingOnly)
            ->post(route('deployments.store'), $payload)
            ->assertSessionHasErrors('staging_only');

        // The same request with staging_only ticked is allowed.
        $this->actingAs($stagingOnly)
            ->post(route('deployments.store'), $payload + ['staging_only' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Deployment::query()->latest('id')->firstOrFail()->opts()->stagingOnly);
    }

    #[Test]
    public function the_review_screen_shows_the_salt_calls_without_creating_anything(): void
    {
        $repository = Repository::factory()->create(['name' => 'Echo']);
        DeployTarget::factory()->proxy()->create(['hostname' => 'proxy-1']);

        $response = $this->actingAs($this->admin())->post(route('deployments.review'), [
            'refs' => [['repository_id' => $repository->getKey(), 'ref_type' => 'branch', 'ref_value' => 'master']],
            'parallel' => 1,
            'rollout' => '1',
            'l10n' => '1',
        ]);

        $response->assertOk();
        $response->assertSee('git-checkout');
        $response->assertSee('rsync-remote');
        $response->assertSee('depool');
        $response->assertSee('l10n-rebuild');
        $response->assertSee('mw-01');
        $response->assertSee('proxy-1');

        // Reviewing is not committing.
        $this->assertSame(0, Deployment::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_review_screen_warns_about_active_patches_that_were_not_selected(): void
    {
        $repository = Repository::factory()->create();
        Patch::factory()->forRepository($repository)->create(['name' => 'T12345 hotfix']);

        $response = $this->actingAs($this->admin())->post(route('deployments.review'), [
            'refs' => [['repository_id' => $repository->getKey(), 'ref_type' => 'branch', 'ref_value' => 'master']],
            'parallel' => 1,
        ]);

        $response->assertOk();
        $response->assertSee('T12345 hotfix');
        $response->assertSee('not selected', escape: false);
    }

    #[Test]
    public function parallelism_is_capped_at_the_configured_maximum(): void
    {
        config(['mwdeploy.rollout.max_parallel' => 4]);

        $repository = Repository::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('deployments.store'), [
                'refs' => [['repository_id' => $repository->getKey(), 'ref_type' => 'branch', 'ref_value' => 'master']],
                'parallel' => 99,
            ])
            ->assertSessionHasErrors('parallel');
    }

    /**
     * @param  list<string>  $extra
     */
    private function deployer(array $extra = [Permissions::DEPLOY_EXTENSION]): User
    {
        return $this->userWithPermissions([...$extra, Permissions::DEPLOY_PRODUCTION_SERVERS]);
    }
}
