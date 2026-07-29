<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Apps\AppRegistry;
use App\Apps\Deployments\DeploymentsApp;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The console's apps system: the launcher, the app boundary, and the permission
 * vocabulary that decides both.
 *
 * The launcher is what someone sees after signing in, and `app.access:<id>` is the
 * door in front of each app's whole API. Those two have to agree — a tile that
 * opens onto 403s, or an app reachable by an account with no grant in it, are the
 * two failures worth a test each.
 */
final class ConsoleAppsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_launcher_lists_the_deployments_app(): void
    {
        $user = $this->userWithPermissions([Permissions::DEPLOYMENTS_ACCESS]);

        $this->actingAs($user)
            ->getJson(route('api.apps.index'))
            ->assertOk()
            ->assertJsonPath('apps.0.id', 'deployments')
            ->assertJsonPath('apps.0.name', 'Deployments')
            ->assertJsonPath('apps.0.path', '/deployments')
            ->assertJsonPath('apps.0.accessible', true)
            ->assertJsonPath('apps.0.granted', [Permissions::DEPLOYMENTS_ACCESS]);
    }

    #[Test]
    public function an_account_with_no_grants_sees_the_app_but_cannot_open_it(): void
    {
        // Listed, not hidden: someone who cannot see an app at all has no way to
        // know what to ask for.
        $this->actingAs($this->userWithPermissions([]))
            ->getJson(route('api.apps.index'))
            ->assertOk()
            ->assertJsonPath('apps.0.id', 'deployments')
            ->assertJsonPath('apps.0.accessible', false)
            ->assertJsonPath('apps.0.granted', []);
    }

    #[Test]
    public function the_access_permission_alone_opens_the_app_read_only(): void
    {
        $viewer = $this->userWithPermissions([Permissions::DEPLOYMENTS_ACCESS]);

        // In through the door…
        $this->actingAs($viewer)->getJson(route('api.dashboard'))->assertOk();
        $this->actingAs($viewer)->getJson(route('api.deployments.index'))->assertOk();

        // …and no further: the per-action policies still decide everything inside.
        $this->actingAs($viewer)->postJson(route('api.deployments.store'), [])->assertForbidden();
        $this->actingAs($viewer)->getJson(route('api.targets.index'))->assertForbidden();
    }

    #[Test]
    public function holding_one_of_an_apps_own_permissions_implies_access_to_it(): void
    {
        /*
         * Granting deploy.core has to be enough on its own. A grant that silently
         * does nothing until a second grant is paired with it is a permission model
         * people get wrong at 2am.
         */
        $deployer = $this->userWithPermissions([Permissions::DEPLOY_CORE]);

        $this->assertTrue($deployer->canUseApp('deployments'));

        $this->actingAs($deployer)->getJson(route('api.dashboard'))->assertOk();
    }

    #[Test]
    public function an_account_with_no_grants_in_an_app_is_refused_at_the_door(): void
    {
        $outsider = $this->userWithPermissions([Permissions::USERS_MANAGE]);

        $this->assertFalse($outsider->canUseApp('deployments'));

        // Refused for the whole app, not screen by screen — and told which grant
        // is missing, so the 403 is actionable.
        $this->actingAs($outsider)
            ->getJson(route('api.dashboard'))
            ->assertForbidden()
            ->assertJsonPath('app_access_required', 'deployments');

        $this->actingAs($outsider)->getJson(route('api.deployments.index'))->assertForbidden();
        $this->actingAs($outsider)->getJson(route('api.versions.index'))->assertForbidden();
        $this->actingAs($outsider)->getJson(route('api.patches.index'))->assertForbidden();

        // The console's own screens stay reachable: that is what they hold.
        $this->actingAs($outsider)->getJson(route('api.users.index'))->assertOk();
        $this->actingAs($outsider)->getJson(route('api.bootstrap'))->assertOk();
    }

    #[Test]
    public function a_disabled_app_disappears_from_the_launcher_and_answers_nothing(): void
    {
        config()->set('console.disabled_apps', ['deployments']);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->getJson(route('api.apps.index'))
            ->assertOk()
            ->assertJsonCount(0, 'apps');

        $this->assertFalse($admin->canUseApp('deployments'));

        // 404 rather than 403: a grant would not help, because the app is not here.
        $this->actingAs($admin)->getJson(route('api.dashboard'))->assertNotFound();
    }

    #[Test]
    public function app_access_on_its_own_does_not_require_two_factor(): void
    {
        // Read access changes nothing, so it is not worth nagging about a
        // requirement that does not apply.
        $viewer = $this->userWithPermissions([Permissions::DEPLOYMENTS_ACCESS], twoFactor: false);

        $this->assertFalse($viewer->requiresTwoFactor());

        $this->actingAs($viewer)->get('/')->assertOk();
        $this->actingAs($viewer)->getJson(route('api.dashboard'))->assertOk();
    }

    #[Test]
    public function every_permission_belongs_to_exactly_one_app(): void
    {
        $grouped = [];

        foreach (Permissions::groups() as $app => $names) {
            foreach ($names as $name) {
                $this->assertArrayHasKey(
                    $name,
                    Permissions::all(),
                    "[{$name}] is grouped under [{$app}] but has no description.",
                );

                $this->assertArrayNotHasKey(
                    $name,
                    $grouped,
                    "[{$name}] is grouped under both [".($grouped[$name] ?? '?')."] and [{$app}].",
                );

                $grouped[$name] = $app;
            }
        }

        // A permission belonging to no app is a permission no screen will offer.
        foreach (array_keys(Permissions::all()) as $name) {
            $this->assertArrayHasKey($name, $grouped, "[{$name}] belongs to no app.");
        }
    }

    #[Test]
    public function an_apps_permissions_are_its_group_and_include_its_access_grant(): void
    {
        $app = app(AppRegistry::class)->find('deployments');

        $this->assertInstanceOf(DeploymentsApp::class, $app);
        $this->assertSame(Permissions::DEPLOYMENTS_ACCESS, $app->accessPermission());
        $this->assertSame(Permissions::forApp('deployments'), $app->permissions());
        $this->assertArrayHasKey(Permissions::DEPLOYMENTS_ACCESS, $app->permissions());
        $this->assertArrayHasKey(Permissions::DEPLOY_CORE, $app->permissions());

        // Console permissions are not any app's to grant.
        $this->assertArrayNotHasKey(Permissions::USERS_MANAGE, $app->permissions());
    }

    #[Test]
    public function the_access_screen_groups_the_vocabulary_by_app(): void
    {
        $response = $this->actingAs($this->admin())->getJson(route('api.users.index'));

        $response->assertOk();
        $response->assertJsonPath('permission_groups.0.key', Permissions::CONSOLE);
        $response->assertJsonPath('permission_groups.1.key', 'deployments');

        $console = collect($response->json('permission_groups.0.permissions'))->pluck('name');
        $deployments = collect($response->json('permission_groups.1.permissions'));

        $this->assertTrue($console->contains(Permissions::USERS_MANAGE));
        $this->assertTrue($console->contains(Permissions::ROLES_MANAGE));

        $this->assertTrue($deployments->pluck('name')->contains(Permissions::DEPLOY_CORE));

        // The screen labels the grant that opens an app, because that is the one
        // that changes what someone sees rather than what they can do.
        $this->assertTrue(
            $deployments->firstWhere('name', Permissions::DEPLOYMENTS_ACCESS)['grants_access'],
        );
    }
}
