<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The console's central access management: accounts, roles, and granting an app by
 * ticking its permissions.
 *
 * This is how someone gets into an app at all, so the gates on it matter as much
 * as the gates inside the apps. users.manage administers accounts; the narrower
 * roles.manage redefines what a role grants.
 */
final class AccessManagementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function granting_a_role_the_access_permission_puts_the_app_on_its_members_launcher(): void
    {
        $admin = $this->userWithPermissions([Permissions::USERS_MANAGE, Permissions::ROLES_MANAGE]);

        $role = Role::factory()->create(['name' => 'readers']);
        $member = User::factory()->create();
        $member->roles()->attach($role);

        // Before: the tile is there, locked.
        $this->actingAs($member->fresh())
            ->getJson(route('api.apps.index'))
            ->assertJsonPath('apps.0.accessible', false);

        $this->actingAs($admin)
            ->putJson(route('api.roles.update', $role), [
                'permissions' => [Permissions::DEPLOYMENTS_ACCESS],
            ])
            ->assertOk()
            ->assertJsonPath('role.permissions', [Permissions::DEPLOYMENTS_ACCESS]);

        // After: openable, and the app's own endpoints answer.
        $this->actingAs($member->fresh())
            ->getJson(route('api.apps.index'))
            ->assertJsonPath('apps.0.accessible', true);

        $this->actingAs($member->fresh())->getJson(route('api.dashboard'))->assertOk();
    }

    #[Test]
    public function a_role_reports_which_apps_it_opens(): void
    {
        $role = Role::factory()->create(['name' => 'deployers']);
        $role->permissions()->attach(
            Permission::query()->create(['name' => Permissions::DEPLOY_CORE])->getKey()
        );

        $response = $this->actingAs($this->userWithPermissions([Permissions::USERS_MANAGE]))
            ->getJson(route('api.users.index'));

        $response->assertOk();

        $listed = collect($response->json('roles'))->firstWhere('name', 'deployers');

        $this->assertSame(['deployments'], $listed['apps']);
    }

    #[Test]
    public function creating_a_role_takes_a_name_and_a_set_of_permissions(): void
    {
        $this->actingAs($this->userWithPermissions([Permissions::ROLES_MANAGE]))
            ->postJson(route('api.roles.store'), [
                'name' => 'release-managers',
                'description' => 'Cuts versions, deploys nothing',
                'permissions' => [Permissions::DEPLOYMENTS_ACCESS, Permissions::VERSIONS_MANAGE],
            ])
            ->assertCreated()
            ->assertJsonPath('role.name', 'release-managers');

        $role = Role::query()->where('name', 'release-managers')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            [Permissions::DEPLOYMENTS_ACCESS, Permissions::VERSIONS_MANAGE],
            $role->permissions->pluck('name')->all(),
        );
    }

    #[Test]
    public function a_permission_the_console_does_not_know_is_refused(): void
    {
        // Otherwise a typo becomes a permission row nothing ever checks — a grant
        // that looks like it worked and does nothing.
        $this->actingAs($this->userWithPermissions([Permissions::ROLES_MANAGE]))
            ->putJson(route('api.roles.update', Role::factory()->create()), [
                'permissions' => ['deploy.everythign'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('permissions.0');
    }

    #[Test]
    public function administering_accounts_does_not_include_redefining_roles(): void
    {
        // Deliberately separate: putting someone into an existing role is a smaller
        // act than changing what that role may do to the fleet.
        $accountAdmin = $this->userWithPermissions([Permissions::USERS_MANAGE]);
        $role = Role::factory()->create();

        $this->actingAs($accountAdmin)->getJson(route('api.users.index'))->assertOk();

        $this->actingAs($accountAdmin)
            ->putJson(route('api.roles.update', $role), ['permissions' => [Permissions::DEPLOY_CORE]])
            ->assertForbidden();

        $this->actingAs($accountAdmin)
            ->postJson(route('api.roles.store'), ['name' => 'nope', 'permissions' => []])
            ->assertForbidden();
    }

    #[Test]
    public function repository_scoping_lives_inside_the_deployments_app(): void
    {
        /*
         * It is access administration, so it wants users.manage — but it is about
         * repositories, so it is behind the deployments app's door as well.
         */
        $outsider = $this->userWithPermissions([Permissions::USERS_MANAGE]);

        $this->actingAs($outsider)
            ->getJson(route('api.repository-scope.index'))
            ->assertForbidden()
            ->assertJsonPath('app_access_required', 'deployments');

        $inside = $this->userWithPermissions([Permissions::USERS_MANAGE, Permissions::DEPLOYMENTS_ACCESS]);

        $this->actingAs($inside)->getJson(route('api.repository-scope.index'))->assertOk();

        // And someone in the app without users.manage still cannot edit the grants.
        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_CORE]))
            ->getJson(route('api.repository-scope.index'))
            ->assertForbidden();
    }

    #[Test]
    public function the_seeded_roles_each_open_the_deployments_app(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        foreach (['ops', 'mediawiki-admins', 'beta', 'viewer'] as $name) {
            $role = Role::query()->where('name', $name)->firstOrFail();

            $this->assertContains(
                Permissions::DEPLOYMENTS_ACCESS,
                $role->permissions->pluck('name')->all(),
                "The [{$name}] role should open the Deployments app.",
            );
        }

        // The read-only role opens the app and grants nothing inside it.
        $viewer = Role::query()->where('name', 'viewer')->firstOrFail();

        $this->assertSame([Permissions::DEPLOYMENTS_ACCESS], $viewer->permissions->pluck('name')->all());
    }
}
