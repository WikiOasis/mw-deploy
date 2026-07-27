<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DeploymentIntent;
use App\Enums\RefType;
use App\Enums\RepoAction;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\DeployTarget;
use App\Models\MediaWikiVersion;
use App\Models\RepositoryPermission;
use App\Models\RepositoryVersion;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The UI half of "check permissions in both places", plus the wizard's
 * version-aware validation.
 */
final class WizardAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private MediaWikiVersion $v45;

    private MediaWikiVersion $v46;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->fakeSalt();

        DeployTarget::factory()->create(['hostname' => 'mw-01']);

        $this->v45 = $this->version('1.45');
        $this->v46 = $this->version('1.46');
    }

    #[Test]
    public function a_reader_cannot_open_either_wizard(): void
    {
        $reader = $this->userWithPermissions([]);

        $this->actingAs($reader)->get(route('deployments.create'))->assertForbidden();
        $this->actingAs($reader)->get(route('deployments.undeploy'))->assertForbidden();
    }

    #[Test]
    public function the_undeploy_wizard_needs_an_undeploy_permission_not_a_deploy_one(): void
    {
        $this->extension('Echo', $this->v45);

        $deployer = $this->userWithPermissions([
            Permissions::DEPLOY_EXTENSION,
            Permissions::DEPLOY_PRODUCTION_SERVERS,
        ]);

        $this->actingAs($deployer)->get(route('deployments.create'))->assertOk();
        $this->actingAs($deployer)->get(route('deployments.undeploy'))->assertForbidden();

        $remover = $this->userWithPermissions([
            Permissions::UNDEPLOY_EXTENSION,
            Permissions::DEPLOY_PRODUCTION_SERVERS,
        ]);

        $this->actingAs($remover)->get(route('deployments.undeploy'))->assertOk();
    }

    #[Test]
    public function the_wizard_lists_every_version_a_repository_is_checked_out_in(): void
    {
        $this->extension('Echo', $this->v45, 'REL1_45');
        $this->extension('Echo', $this->v46, 'REL1_46');

        $response = $this->actingAs($this->deployer())->get(route('deployments.create'));

        $response->assertOk();
        $response->assertSee('Echo');
        $response->assertSee('1.45');
        $response->assertSee('1.46');
        // The per-version pins are what make "all versions" do the right thing.
        $response->assertSee('REL1_45');
        $response->assertSee('REL1_46');
    }

    #[Test]
    public function it_creates_one_line_item_per_selected_checkout(): void
    {
        $echo45 = $this->extension('Echo', $this->v45, 'REL1_45');
        $echo46 = $this->extension('Echo', $this->v46, 'REL1_46');

        $this->actingAs($this->deployer())
            ->post(route('deployments.store'), [
                'intent' => 'deploy',
                'items' => [
                    ['repository_version_id' => $echo45->getKey(), 'ref_type' => 'branch', 'ref_value' => 'REL1_45'],
                    ['repository_version_id' => $echo46->getKey(), 'ref_type' => 'branch', 'ref_value' => 'REL1_46'],
                ],
                'parallel' => 1,
            ])
            ->assertSessionHasNoErrors();

        $deployment = Deployment::query()->latest('id')->firstOrFail();

        $this->assertSame(2, $deployment->repoRefs()->count());
        $this->assertSame(
            ['REL1_45', 'REL1_46'],
            $deployment->repoRefs()->orderBy('id')->pluck('ref_value')->all(),
        );

        Queue::assertPushed(RunDeployment::class);
    }

    #[Test]
    public function an_undeploy_submission_stores_removal_actions_and_no_refs(): void
    {
        $echo = $this->extension('Echo', $this->v45);

        $this->actingAs($this->remover())
            ->post(route('deployments.store'), [
                'intent' => 'undeploy',
                'items' => [['repository_version_id' => $echo->getKey()]],
                'parallel' => 1,
            ])
            ->assertSessionHasNoErrors();

        $deployment = Deployment::query()->latest('id')->firstOrFail();
        $ref = $deployment->repoRefs()->firstOrFail();

        $this->assertSame(DeploymentIntent::Undeploy, $deployment->intent);
        $this->assertSame(RepoAction::Undeploy, $ref->action);
        $this->assertNull($ref->ref_value);
    }

    #[Test]
    public function an_undeploy_submission_that_smuggles_a_ref_is_rejected(): void
    {
        $echo = $this->extension('Echo', $this->v45);

        $this->actingAs($this->remover())
            ->post(route('deployments.store'), [
                'intent' => 'undeploy',
                'items' => [['repository_version_id' => $echo->getKey(), 'ref_value' => 'master']],
                'parallel' => 1,
            ])
            ->assertSessionHasErrors('items.0.ref_value');

        $this->assertSame(0, Deployment::query()->count());
    }

    #[Test]
    public function an_already_undeployed_checkout_cannot_be_undeployed_again(): void
    {
        $echo = RepositoryVersion::factory()
            ->of($this->extension('Thanks', $this->v45)->repository, $this->v46)
            ->undeployed()
            ->create();

        $this->actingAs($this->remover())
            ->post(route('deployments.store'), [
                'intent' => 'undeploy',
                'items' => [['repository_version_id' => $echo->getKey()]],
                'parallel' => 1,
            ])
            ->assertSessionHasErrors('items.0.repository_version_id');
    }

    #[Test]
    public function a_deployer_cannot_submit_a_removal_by_flipping_the_intent(): void
    {
        $echo = $this->extension('Echo', $this->v45);

        $this->actingAs($this->deployer())
            ->post(route('deployments.store'), [
                'intent' => 'undeploy',
                'items' => [['repository_version_id' => $echo->getKey()]],
                'parallel' => 1,
            ])
            ->assertForbidden();

        $this->assertSame(0, Deployment::query()->count());
    }

    #[Test]
    public function a_sha_typed_into_the_branch_field_is_still_stored_as_a_commit(): void
    {
        $echo = $this->extension('Echo', $this->v45);

        $this->actingAs($this->deployer())->post(route('deployments.store'), [
            'items' => [[
                'repository_version_id' => $echo->getKey(),
                'ref_type' => 'branch',
                'ref_value' => '4a9f2e1bd7c3a1f0e5b2c9d8a7f6e5d4c3b2a1f0',
            ]],
            'parallel' => 1,
        ]);

        $this->assertSame(
            RefType::Commit,
            Deployment::query()->latest('id')->firstOrFail()->repoRefs()->firstOrFail()->ref_type,
        );
    }

    #[Test]
    public function it_rejects_a_ref_containing_shell_metacharacters(): void
    {
        $echo = $this->extension('Echo', $this->v45);

        $this->actingAs($this->deployer())
            ->post(route('deployments.store'), [
                'items' => [[
                    'repository_version_id' => $echo->getKey(),
                    'ref_type' => 'branch',
                    'ref_value' => 'master; rm -rf /srv',
                ]],
                'parallel' => 1,
            ])
            ->assertSessionHasErrors('items.0.ref_value');

        $this->assertSame(0, Deployment::query()->count());
    }

    #[Test]
    public function per_repository_scoping_applies_to_removal_as_well_as_deployment(): void
    {
        $mine = $this->extension('Echo', $this->v45);
        $theirs = $this->extension('Thanks', $this->v45);

        $user = $this->userWithPermissions([
            Permissions::DEPLOY_EXTENSION,
            Permissions::UNDEPLOY_EXTENSION,
            Permissions::DEPLOY_PRODUCTION_SERVERS,
        ]);

        RepositoryPermission::query()->create([
            'repository_id' => $theirs->repository_id,
            'user_id' => User::factory()->create()->getKey(),
        ]);

        $this->assertTrue($user->canDeployRepository($mine->repository));
        $this->assertTrue($user->canUndeployRepository($mine->repository));
        $this->assertFalse($user->canDeployRepository($theirs->repository));
        $this->assertFalse($user->canUndeployRepository($theirs->repository));
    }

    #[Test]
    public function only_an_account_with_the_force_permission_may_set_force(): void
    {
        $echo = $this->extension('Echo', $this->v45);

        $payload = [
            'items' => [['repository_version_id' => $echo->getKey(), 'ref_value' => 'master']],
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
        $echo = $this->extension('Echo', $this->v45);

        $stagingOnly = $this->userWithPermissions([Permissions::DEPLOY_EXTENSION]);

        $payload = [
            'items' => [['repository_version_id' => $echo->getKey(), 'ref_value' => 'master']],
            'parallel' => 1,
        ];

        $this->actingAs($stagingOnly)
            ->post(route('deployments.store'), $payload)
            ->assertSessionHasErrors('staging_only');

        $this->actingAs($stagingOnly)
            ->post(route('deployments.store'), $payload + ['staging_only' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Deployment::query()->latest('id')->firstOrFail()->opts()->stagingOnly);
    }

    #[Test]
    public function the_review_screen_shows_the_salt_calls_without_creating_anything(): void
    {
        $echo45 = $this->extension('Echo', $this->v45, 'REL1_45');
        $echo46 = $this->extension('Echo', $this->v46, 'REL1_46');
        DeployTarget::factory()->proxy()->create(['hostname' => 'proxy-1']);

        $response = $this->actingAs($this->admin())->post(route('deployments.review'), [
            'items' => [
                ['repository_version_id' => $echo45->getKey(), 'ref_value' => 'REL1_45'],
                ['repository_version_id' => $echo46->getKey(), 'ref_value' => 'REL1_46'],
            ],
            'parallel' => 1,
            'rollout' => '1',
        ]);

        $response->assertOk();
        $response->assertSee('git-checkout');
        $response->assertSee('versions/1.45/extensions/Echo');
        $response->assertSee('versions/1.46/extensions/Echo');
        $response->assertSee('depool');
        $response->assertSee('mw-01');

        $this->assertSame(0, Deployment::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_undeploy_review_screen_shows_the_literal_removal_commands(): void
    {
        $echo = $this->extension('Echo', $this->v45);

        $response = $this->actingAs($this->admin())->post(route('deployments.review'), [
            'intent' => 'undeploy',
            'items' => [['repository_version_id' => $echo->getKey()]],
            'parallel' => 1,
        ]);

        $response->assertOk();
        // An operator about to delete a directory off the fleet should see the
        // path and the root guard, not a euphemism.
        $response->assertSee('repo-remove');
        $response->assertSee('--root');
        $response->assertSee('versions/1.45/extensions/Echo');
        $response->assertSee('Remove them');
        // Never the version-root escape hatch for a per-checkout removal.
        $response->assertDontSee('allow-version-root');
    }

    #[Test]
    public function parallelism_is_capped_at_the_configured_maximum(): void
    {
        config(['mwdeploy.rollout.max_parallel' => 4]);

        $echo = $this->extension('Echo', $this->v45);

        $this->actingAs($this->admin())
            ->post(route('deployments.store'), [
                'items' => [['repository_version_id' => $echo->getKey(), 'ref_value' => 'master']],
                'parallel' => 99,
            ])
            ->assertSessionHasErrors('parallel');
    }

    #[Test]
    public function cutting_a_version_needs_versions_manage(): void
    {
        $this->core($this->v45);

        $this->actingAs($this->deployer())
            ->post(route('versions.store'), ['version' => '1.47', 'core_ref' => 'REL1_47'])
            ->assertForbidden();

        $this->assertSame(2, MediaWikiVersion::query()->count());
    }

    #[Test]
    public function removing_a_version_needs_the_typed_confirmation(): void
    {
        $this->core($this->v45);

        $this->actingAs($this->admin())
            ->post(route('versions.undeploy', $this->v45), ['confirm_version' => '1.46'])
            ->assertSessionHasErrors('confirm_version');

        $this->assertSame(0, Deployment::query()->count());

        $this->actingAs($this->admin())
            ->post(route('versions.undeploy', $this->v45), ['confirm_version' => '1.45'])
            ->assertSessionHasNoErrors();

        $this->assertSame(DeploymentIntent::VersionUndeploy, Deployment::query()->latest('id')->firstOrFail()->intent);
    }

    #[Test]
    public function removing_a_version_needs_the_version_undeploy_permission(): void
    {
        $this->core($this->v45);

        $this->actingAs($this->remover())
            ->post(route('versions.undeploy', $this->v45), ['confirm_version' => '1.45'])
            ->assertForbidden();
    }

    /**
     * @param  list<string>  $extra
     */
    private function deployer(array $extra = [Permissions::DEPLOY_EXTENSION]): User
    {
        return $this->userWithPermissions([...$extra, Permissions::DEPLOY_PRODUCTION_SERVERS]);
    }

    private function remover(): User
    {
        return $this->userWithPermissions([
            Permissions::UNDEPLOY_EXTENSION,
            Permissions::DEPLOY_PRODUCTION_SERVERS,
        ]);
    }
}
