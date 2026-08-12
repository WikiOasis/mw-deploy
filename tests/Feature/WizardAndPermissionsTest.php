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
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The API half of "check permissions in both places", plus the wizard's
 * version-aware validation.
 *
 * These hit the endpoints the SPA calls. The job re-derives the same answers
 * through DeploymentAuthorizer, which RolloutBehaviourTest covers — a permission
 * check that only exists in the UI is a permission check that does not exist.
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

        $this->actingAs($reader)->getJson($this->wizard('deploy'))->assertForbidden();
        $this->actingAs($reader)->getJson($this->wizard('undeploy'))->assertForbidden();
    }

    #[Test]
    public function the_undeploy_wizard_needs_an_undeploy_permission_not_a_deploy_one(): void
    {
        $this->extension('Echo', $this->v45);

        $deployer = $this->userWithPermissions([
            Permissions::DEPLOY_EXTENSION,
            Permissions::DEPLOY_PRODUCTION_SERVERS,
        ]);

        $this->actingAs($deployer)->getJson($this->wizard('deploy'))->assertOk();
        $this->actingAs($deployer)->getJson($this->wizard('undeploy'))->assertForbidden();

        $remover = $this->userWithPermissions([
            Permissions::UNDEPLOY_EXTENSION,
            Permissions::DEPLOY_PRODUCTION_SERVERS,
        ]);

        $this->actingAs($remover)->getJson($this->wizard('undeploy'))->assertOk();
    }

    #[Test]
    public function the_wizard_lists_every_version_a_repository_is_checked_out_in(): void
    {
        $this->extension('Echo', $this->v45, 'REL1_45');
        $this->extension('Echo', $this->v46, 'REL1_46');

        $response = $this->actingAs($this->deployer())->getJson($this->wizard('deploy'));

        $response->assertOk();

        $checkouts = collect($response->json('repositories'))
            ->firstWhere('name', 'Echo')['checkouts'];

        $this->assertSame(['1.46', '1.45'], array_column($checkouts, 'version'));
        // The per-version pins are what make "all versions" do the right thing.
        $this->assertSame(['REL1_46', 'REL1_45'], array_column($checkouts, 'resolved_ref'));
    }

    #[Test]
    public function the_undeploy_wizard_offers_only_checkouts_that_are_on_disk(): void
    {
        $present = $this->extension('Echo', $this->v45);

        RepositoryVersion::factory()
            ->of($present->repository, $this->v46)
            ->undeployed()
            ->create();

        $response = $this->actingAs($this->remover())->getJson($this->wizard('undeploy'));

        $checkouts = collect($response->json('repositories'))->firstWhere('name', 'Echo')['checkouts'];

        $this->assertSame([$present->getKey()], array_column($checkouts, 'id'));
    }

    #[Test]
    public function it_creates_one_line_item_per_selected_checkout(): void
    {
        $echo45 = $this->extension('Echo', $this->v45, 'REL1_45');
        $echo46 = $this->extension('Echo', $this->v46, 'REL1_46');

        $this->actingAs($this->deployer())
            ->postJson(route('api.deployments.store'), [
                'intent' => 'deploy',
                'items' => [
                    ['repository_version_id' => $echo45->getKey(), 'ref_type' => 'branch', 'ref_value' => 'REL1_45'],
                    ['repository_version_id' => $echo46->getKey(), 'ref_type' => 'branch', 'ref_value' => 'REL1_46'],
                ],
                'parallel' => 1,
            ])
            ->assertCreated();

        $deployment = Deployment::query()->latest('id')->firstOrFail();

        $this->assertSame(2, $deployment->repoRefs()->count());
        $this->assertSame(
            ['REL1_45', 'REL1_46'],
            $deployment->repoRefs()->orderBy('id')->pluck('ref_value')->all(),
        );

        Queue::assertPushed(RunDeployment::class);
    }

    #[Test]
    public function every_extension_in_one_version_can_go_on_one_ref_in_a_single_submission(): void
    {
        // The upgrade shape the wizard's bulk selector produces: one type, one
        // version, one branch, and one line item per checkout — each still carrying
        // its own ref, because that is what makes a per-row correction possible.
        $checkouts = collect(['Echo', 'Thanks', 'CodeMirror'])
            ->map(fn (string $name): RepositoryVersion => $this->extension($name, $this->v46, 'master'));

        // A checkout of the same type in the *other* version must not be dragged in.
        $echo45 = $this->extension('Echo', $this->v45, 'REL1_45');

        $this->actingAs($this->deployer())
            ->postJson(route('api.deployments.store'), [
                'items' => $checkouts->map(fn (RepositoryVersion $checkout): array => [
                    'repository_version_id' => $checkout->getKey(),
                    'ref_type' => 'branch',
                    'ref_value' => 'REL1_46',
                ])->all(),
                'parallel' => 1,
            ])
            ->assertCreated();

        $refs = Deployment::query()->latest('id')->firstOrFail()->repoRefs;

        $this->assertSame(3, $refs->count());
        $this->assertSame(['REL1_46'], $refs->pluck('ref_value')->unique()->values()->all());
        $this->assertNotContains($echo45->getKey(), $refs->pluck('repository_version_id')->all());
    }

    #[Test]
    public function a_line_item_naming_a_checkout_that_does_not_exist_is_refused(): void
    {
        $echo = $this->extension('Echo', $this->v45);

        $this->actingAs($this->deployer())
            ->postJson(route('api.deployments.store'), [
                'items' => [
                    ['repository_version_id' => $echo->getKey(), 'ref_value' => 'REL1_45'],
                    ['repository_version_id' => $echo->getKey() + 5000, 'ref_value' => 'REL1_45'],
                ],
                'parallel' => 1,
            ])
            ->assertJsonValidationErrors('items.1.repository_version_id');

        $this->assertSame(0, Deployment::query()->count());
    }

    #[Test]
    public function an_undeploy_submission_stores_removal_actions_and_no_refs(): void
    {
        $echo = $this->extension('Echo', $this->v45);

        $this->actingAs($this->remover())
            ->postJson(route('api.deployments.store'), [
                'intent' => 'undeploy',
                'items' => [['repository_version_id' => $echo->getKey()]],
                'parallel' => 1,
            ])
            ->assertCreated();

        $deployment = Deployment::query()->latest('id')->firstOrFail();
        $ref = $deployment->repoRefs()->firstOrFail();

        $this->assertSame(DeploymentIntent::Undeploy, $deployment->intent);
        $this->assertSame(RepoAction::Undeploy, $ref->action);
        $this->assertNull($ref->ref_value);
    }

    #[Test]
    public function the_plan_screen_can_be_confirmed_for_an_undeploy(): void
    {
        // The review step does not resubmit the wizard's own payload verbatim: it
        // posts back exactly what /plan echoed, and StoreDeploymentRequest::items()
        // echoes an undeploy's line items with an explicit ref_value of null
        // rather than omitting the key. A ref_value ruleset that applies its
        // string/regex checks unconditionally fails that null outright, even
        // though nothing was smuggled in — this is the shape that must keep
        // working end to end, not just the wizard's first, key-omitting request.
        $echo = $this->extension('Echo', $this->v45);

        $plan = $this->actingAs($this->remover())
            ->postJson(route('api.deployments.plan'), [
                'intent' => 'undeploy',
                'items' => [['repository_version_id' => $echo->getKey()]],
                'parallel' => 1,
            ])
            ->assertOk()
            ->json('payload');

        $this->assertNull($plan['items'][0]['ref_value']);

        $this->actingAs($this->remover())
            ->postJson(route('api.deployments.store'), $plan)
            ->assertCreated();

        $deployment = Deployment::query()->latest('id')->firstOrFail();
        $this->assertSame(DeploymentIntent::Undeploy, $deployment->intent);
        $this->assertSame(RepoAction::Undeploy, $deployment->repoRefs()->firstOrFail()->action);
    }

    #[Test]
    public function an_undeploy_submission_that_smuggles_a_ref_is_rejected(): void
    {
        $echo = $this->extension('Echo', $this->v45);

        $this->actingAs($this->remover())
            ->postJson(route('api.deployments.store'), [
                'intent' => 'undeploy',
                'items' => [['repository_version_id' => $echo->getKey(), 'ref_value' => 'master']],
                'parallel' => 1,
            ])
            ->assertJsonValidationErrors('items.0.ref_value');

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
            ->postJson(route('api.deployments.store'), [
                'intent' => 'undeploy',
                'items' => [['repository_version_id' => $echo->getKey()]],
                'parallel' => 1,
            ])
            ->assertJsonValidationErrors('items.0.repository_version_id');
    }

    #[Test]
    public function a_deployer_cannot_submit_a_removal_by_flipping_the_intent(): void
    {
        $echo = $this->extension('Echo', $this->v45);

        $this->actingAs($this->deployer())
            ->postJson(route('api.deployments.store'), [
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

        $this->actingAs($this->deployer())->postJson(route('api.deployments.store'), [
            'items' => [[
                'repository_version_id' => $echo->getKey(),
                'ref_type' => 'branch',
                'ref_value' => '4a9f2e1bd7c3a1f0e5b2c9d8a7f6e5d4c3b2a1f0',
            ]],
            'parallel' => 1,
        ])->assertCreated();

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
            ->postJson(route('api.deployments.store'), [
                'items' => [[
                    'repository_version_id' => $echo->getKey(),
                    'ref_type' => 'branch',
                    'ref_value' => 'master; rm -rf /srv',
                ]],
                'parallel' => 1,
            ])
            ->assertJsonValidationErrors('items.0.ref_value');

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

        // …and the wizard does not offer what the user may not touch.
        $response = $this->actingAs($user)->getJson($this->wizard('deploy'));

        $this->assertSame(['Echo'], array_column($response->json('repositories'), 'name'));
    }

    #[Test]
    public function only_an_account_with_the_force_permission_may_set_force(): void
    {
        $echo = $this->extension('Echo', $this->v45);

        $payload = [
            'items' => [['repository_version_id' => $echo->getKey(), 'ref_value' => 'master']],
            'parallel' => 1,
            'force' => true,
        ];

        $this->actingAs($this->deployer())
            ->postJson(route('api.deployments.store'), $payload)
            ->assertJsonValidationErrors('force');

        $this->actingAs($this->admin())
            ->postJson(route('api.deployments.store'), $payload)
            ->assertCreated();

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
            ->postJson(route('api.deployments.store'), $payload)
            ->assertJsonValidationErrors('staging_only');

        $this->actingAs($stagingOnly)
            ->postJson(route('api.deployments.store'), $payload + ['staging_only' => true])
            ->assertCreated();

        $this->assertTrue(Deployment::query()->latest('id')->firstOrFail()->opts()->stagingOnly);

        // The wizard tells the SPA to fix the toggle on, rather than offering a
        // choice the server would refuse.
        $this->actingAs($stagingOnly)
            ->getJson($this->wizard('deploy'))
            ->assertJsonPath('defaults.staging_only', true)
            ->assertJsonPath('can.target_production', false);
    }

    #[Test]
    public function the_plan_endpoint_shows_the_salt_calls_without_creating_anything(): void
    {
        $echo45 = $this->extension('Echo', $this->v45, 'REL1_45');
        $echo46 = $this->extension('Echo', $this->v46, 'REL1_46');
        DeployTarget::factory()->proxy()->create(['hostname' => 'proxy-1']);

        $response = $this->actingAs($this->admin())->postJson(route('api.deployments.plan'), [
            'items' => [
                ['repository_version_id' => $echo45->getKey(), 'ref_value' => 'REL1_45'],
                ['repository_version_id' => $echo46->getKey(), 'ref_value' => 'REL1_46'],
            ],
            'parallel' => 1,
            'rollout' => true,
        ]);

        $response->assertOk();

        $commands = $this->commandsIn($response);

        $this->assertStringContainsString('git-checkout', $commands);
        $this->assertStringContainsString('versions/1.45/extensions/Echo', $commands);
        $this->assertStringContainsString('versions/1.46/extensions/Echo', $commands);
        $this->assertStringContainsString('depool', $commands);
        $this->assertStringContainsString('mw-01', $commands);

        $this->assertSame(0, Deployment::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_undeploy_plan_shows_the_literal_removal_commands(): void
    {
        $echo = $this->extension('Echo', $this->v45);

        $response = $this->actingAs($this->admin())->postJson(route('api.deployments.plan'), [
            'intent' => 'undeploy',
            'items' => [['repository_version_id' => $echo->getKey()]],
            'parallel' => 1,
        ]);

        $response->assertOk();
        $response->assertJsonPath('removes_anything', true);

        $commands = $this->commandsIn($response);

        // An operator about to delete a directory off the fleet should see the path
        // and the root guard, not a euphemism.
        $this->assertStringContainsString('repo-remove', $commands);
        $this->assertStringContainsString('--root', $commands);
        $this->assertStringContainsString('versions/1.45/extensions/Echo', $commands);
        // Never the version-root escape hatch for a per-checkout removal.
        $this->assertStringNotContainsString('allow-version-root', $commands);
    }

    #[Test]
    public function the_plan_lists_registered_patches_that_were_not_selected(): void
    {
        $echo = $this->extension('Echo', $this->v45);
        $patch = $this->patchFor($echo);

        $response = $this->actingAs($this->admin())->postJson(route('api.deployments.plan'), [
            'items' => [['repository_version_id' => $echo->getKey(), 'ref_value' => 'REL1_45']],
            'parallel' => 1,
        ]);

        $response->assertOk();
        $response->assertJsonPath('unselected_patches.0.id', $patch->getKey());
        $response->assertJsonCount(0, 'patches');
    }

    #[Test]
    public function parallelism_is_capped_at_the_configured_maximum(): void
    {
        config(['mwdeploy.rollout.max_parallel' => 4]);

        $echo = $this->extension('Echo', $this->v45);

        $this->actingAs($this->admin())
            ->postJson(route('api.deployments.store'), [
                'items' => [['repository_version_id' => $echo->getKey(), 'ref_value' => 'master']],
                'parallel' => 99,
            ])
            ->assertJsonValidationErrors('parallel');
    }

    #[Test]
    public function cutting_a_version_needs_versions_manage(): void
    {
        $this->core($this->v45);

        $this->actingAs($this->deployer())
            ->postJson(route('api.versions.store'), ['version' => '1.47', 'core_ref' => 'REL1_47'])
            ->assertForbidden();

        $this->assertSame(2, MediaWikiVersion::query()->count());
    }

    #[Test]
    public function removing_a_version_needs_the_typed_confirmation(): void
    {
        $this->core($this->v45);

        $this->actingAs($this->admin())
            ->postJson(route('api.versions.undeploy', $this->v45), ['confirm_version' => '1.46'])
            ->assertJsonValidationErrors('confirm_version');

        $this->assertSame(0, Deployment::query()->count());

        $this->actingAs($this->admin())
            ->postJson(route('api.versions.undeploy', $this->v45), ['confirm_version' => '1.45'])
            ->assertCreated();

        $this->assertSame(DeploymentIntent::VersionUndeploy, Deployment::query()->latest('id')->firstOrFail()->intent);
    }

    #[Test]
    public function removing_a_version_needs_the_version_undeploy_permission(): void
    {
        $this->core($this->v45);

        $this->actingAs($this->remover())
            ->postJson(route('api.versions.undeploy', $this->v45), ['confirm_version' => '1.45'])
            ->assertForbidden();
    }

    private function wizard(string $intent): string
    {
        return route('api.deployments.wizard', ['intent' => $intent]);
    }

    /**
     * Every planned command line, flattened, so a test can assert on the sequence
     * the operator would be shown.
     */
    private function commandsIn(TestResponse $response): string
    {
        return collect($response->json('phases'))
            ->flatten(1)
            ->pluck('command')
            ->implode("\n");
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
