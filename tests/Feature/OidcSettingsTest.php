<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OidcRoleMapping;
use App\Models\OidcSettings;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Configuring single sign-on from the console.
 *
 * The configuration lives in the database so that rotating a client secret or
 * adding a group does not need a shell on the appserver — which means the API
 * that writes it is now part of the console's attack surface. Hence: its own
 * permission, a client secret that only ever travels inwards, and a refusal to
 * switch on a configuration that cannot work.
 */
final class OidcSettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A configuration the endpoint will accept.
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'enabled' => true,
            'label' => 'Example SSO',
            'discovery_url' => 'https://sso.example.test/application/o/console/',
            'issuer' => 'https://sso.example.test/application/o/console',
            'client_id' => 'wikioasis-console',
            'client_secret' => 'the-secret',
            'authorization_endpoint' => 'https://sso.example.test/application/o/authorize/',
            'token_endpoint' => 'https://sso.example.test/application/o/token/',
            'userinfo_endpoint' => 'https://sso.example.test/application/o/userinfo/',
            'jwks_uri' => 'https://sso.example.test/application/o/console/jwks/',
            'scopes' => 'openid profile email groups',
            'groups_claim' => 'groups',
            'password_login_enabled' => true,
            'create_users' => true,
            'sync_roles' => true,
            ...$overrides,
        ];
    }

    #[Test]
    public function the_screen_is_behind_its_own_permission(): void
    {
        // Managing accounts and managing roles are both smaller acts than
        // deciding which identity provider the console believes.
        $user = $this->userWithPermissions([Permissions::USERS_MANAGE, Permissions::ROLES_MANAGE]);

        $this->actingAs($user)->getJson(route('api.settings.oidc.show'))->assertForbidden();
        $this->actingAs($user)->putJson(route('api.settings.oidc.update'), $this->payload())->assertForbidden();
        $this->actingAs($user)->postJson(route('api.settings.oidc.discover'), [
            'discovery_url' => 'https://sso.example.test/',
        ])->assertForbidden();
    }

    #[Test]
    public function it_shows_the_redirect_uri_to_register_at_the_provider(): void
    {
        $this->actingAs($this->settingsAdmin())
            ->getJson(route('api.settings.oidc.show'))
            ->assertOk()
            ->assertJsonPath('redirect_uri', route('oidc.callback'))
            ->assertJsonPath('settings.enabled', false)
            ->assertJsonPath('settings.usable', false);
    }

    #[Test]
    public function reading_the_configuration_never_returns_the_client_secret(): void
    {
        $this->actingAs($this->settingsAdmin())
            ->putJson(route('api.settings.oidc.update'), $this->payload())
            ->assertOk();

        $response = $this->actingAs($this->settingsAdmin())
            ->getJson(route('api.settings.oidc.show'))
            ->assertOk();

        $response->assertJsonPath('settings.client_secret_set', true);
        $response->assertJsonMissingPath('settings.client_secret');
        $this->assertStringNotContainsString('the-secret', $response->getContent());
    }

    #[Test]
    public function saving_without_a_secret_keeps_the_one_already_stored(): void
    {
        $admin = $this->settingsAdmin();

        $this->actingAs($admin)->putJson(route('api.settings.oidc.update'), $this->payload())->assertOk();

        $payload = $this->payload();
        unset($payload['client_secret']);

        $this->actingAs($admin)->putJson(route('api.settings.oidc.update'), $payload)->assertOk();

        $this->assertSame('the-secret', OidcSettings::current()->client_secret);
    }

    #[Test]
    public function a_new_secret_replaces_the_stored_one(): void
    {
        $admin = $this->settingsAdmin();

        $this->actingAs($admin)->putJson(route('api.settings.oidc.update'), $this->payload())->assertOk();
        $this->actingAs($admin)
            ->putJson(route('api.settings.oidc.update'), $this->payload(['client_secret' => 'rotated']))
            ->assertOk();

        $this->assertSame('rotated', OidcSettings::current()->client_secret);
    }

    #[Test]
    public function the_secret_is_encrypted_at_rest(): void
    {
        $this->actingAs($this->settingsAdmin())
            ->putJson(route('api.settings.oidc.update'), $this->payload())
            ->assertOk();

        $stored = (string) $this->getConnection()->table('oidc_settings')->value('client_secret');

        $this->assertNotSame('the-secret', $stored);
        $this->assertStringNotContainsString('the-secret', $stored);
    }

    #[Test]
    public function it_refuses_to_switch_on_a_configuration_it_cannot_use(): void
    {
        // A sign-in button that leads to an error page gets reported as the
        // console being broken, so it is never offered in the first place.
        $this->actingAs($this->settingsAdmin())
            ->putJson(route('api.settings.oidc.update'), $this->payload(['client_id' => null, 'client_secret' => null]))
            ->assertStatus(422);

        $this->assertFalse(OidcSettings::current()->enabled);
    }

    #[Test]
    public function switching_it_on_requires_a_key_set_url_because_signatures_are_always_checked(): void
    {
        $this->actingAs($this->settingsAdmin())
            ->putJson(route('api.settings.oidc.update'), $this->payload(['jwks_uri' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('jwks_uri');
    }

    #[Test]
    public function a_configuration_can_be_saved_while_switched_off(): void
    {
        $this->actingAs($this->settingsAdmin())
            ->putJson(route('api.settings.oidc.update'), [
                'enabled' => false,
                'label' => 'Example SSO',
                'password_login_enabled' => true,
                'scopes' => 'openid profile email groups',
                'groups_claim' => 'groups',
                'create_users' => true,
                'sync_roles' => true,
            ])
            ->assertOk();

        $this->assertFalse(OidcSettings::current()->enabled);
    }

    #[Test]
    public function it_refuses_cleartext_endpoints(): void
    {
        /*
         * The token endpoint carries the client secret and the key set decides
         * whether an ID token is genuine. Neither may travel over plain HTTP, so
         * this is a validation rule rather than advice in the documentation.
         */
        $admin = $this->settingsAdmin();

        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'jwks_uri', 'discovery_url'] as $field) {
            $this->actingAs($admin)
                ->putJson(route('api.settings.oidc.update'), $this->payload([
                    $field => 'http://sso.example.test/insecure',
                ]))
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }
    }

    #[Test]
    public function it_refuses_an_endpoint_in_the_link_local_range(): void
    {
        // Nothing legitimate serves OpenID Connect from 169.254.0.0/16; it is
        // where a cloud instance keeps its credentials.
        $this->actingAs($this->settingsAdmin())
            ->putJson(route('api.settings.oidc.update'), $this->payload([
                'jwks_uri' => 'https://169.254.169.254/latest/meta-data/',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('jwks_uri');
    }

    #[Test]
    public function a_loopback_provider_is_allowed_without_tls(): void
    {
        // A developer running an IdP on localhost has no certificate for it, and
        // no network for a packet to be read on.
        $this->actingAs($this->settingsAdmin())
            ->putJson(route('api.settings.oidc.update'), $this->payload([
                'issuer' => 'http://localhost:9000/application/o/console',
                'authorization_endpoint' => 'http://localhost:9000/authorize',
                'token_endpoint' => 'http://localhost:9000/token',
                'userinfo_endpoint' => 'http://localhost:9000/userinfo',
                'jwks_uri' => 'http://localhost:9000/jwks',
                'discovery_url' => 'http://localhost:9000/.well-known/openid-configuration',
            ]))
            ->assertOk();

        $this->assertTrue(OidcSettings::current()->enabled);
    }

    #[Test]
    public function discovery_refuses_a_provider_that_advertises_cleartext_endpoints(): void
    {
        Http::fake([
            '*' => Http::response([
                'issuer' => 'https://sso.example.test',
                'authorization_endpoint' => 'https://sso.example.test/authorize',
                'token_endpoint' => 'http://sso.example.test/token',
            ]),
        ]);

        $this->actingAs($this->settingsAdmin())
            ->postJson(route('api.settings.oidc.discover'), ['discovery_url' => 'https://sso.example.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('discovery_url');
    }

    #[Test]
    public function discovery_will_not_fetch_a_cleartext_or_link_local_url(): void
    {
        Http::fake();

        foreach (['http://sso.example.test', 'https://169.254.169.254/'] as $url) {
            $this->actingAs($this->settingsAdmin())
                ->postJson(route('api.settings.oidc.discover'), ['discovery_url' => $url])
                ->assertStatus(422)
                ->assertJsonValidationErrors('discovery_url');
        }

        // Refused before anything left the box, not after.
        Http::assertNothingSent();
    }

    #[Test]
    public function there_is_only_ever_one_settings_row(): void
    {
        /*
         * Two rows would be two answers to "who are you", and sign-in would use
         * whichever the database returned first. The column is uniquely indexed,
         * so the second insert fails rather than creating a rival configuration.
         */
        $admin = $this->settingsAdmin();

        $this->actingAs($admin)->putJson(route('api.settings.oidc.update'), $this->payload())->assertOk();
        $this->actingAs($admin)->putJson(route('api.settings.oidc.update'), $this->payload(['label' => 'Renamed']))->assertOk();

        $this->assertSame(1, OidcSettings::query()->count());
        $this->assertSame('Renamed', OidcSettings::current()->label);

        $this->expectException(QueryException::class);

        OidcSettings::query()->create(['label' => 'A rival configuration']);
    }

    #[Test]
    public function group_mappings_are_saved_and_removed(): void
    {
        $admin = $this->settingsAdmin();
        $ops = Role::factory()->create(['name' => 'ops']);
        $viewer = Role::factory()->create(['name' => 'viewer']);

        $this->actingAs($admin)->putJson(route('api.settings.oidc.update'), $this->payload([
            'role_mappings' => [
                ['group' => 'ops', 'role_id' => $ops->getKey()],
                ['group' => 'everyone', 'role_id' => $viewer->getKey()],
            ],
        ]))->assertOk()->assertJsonCount(2, 'role_mappings');

        // Saving a shorter list is how a mapping is removed.
        $this->actingAs($admin)->putJson(route('api.settings.oidc.update'), $this->payload([
            'role_mappings' => [['group' => 'ops', 'role_id' => $ops->getKey()]],
        ]))->assertOk()->assertJsonCount(1, 'role_mappings');

        $this->assertSame(['ops'], OidcRoleMapping::query()->pluck('group')->all());
    }

    #[Test]
    public function a_mapping_cannot_point_at_a_role_that_does_not_exist(): void
    {
        $this->actingAs($this->settingsAdmin())
            ->putJson(route('api.settings.oidc.update'), $this->payload([
                'role_mappings' => [['group' => 'ops', 'role_id' => 9999]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_mappings.0.role_id');
    }

    #[Test]
    public function deleting_a_role_takes_its_mappings_with_it(): void
    {
        $role = Role::factory()->create(['name' => 'temporary']);
        OidcRoleMapping::create(['group' => 'temps', 'role_id' => $role->getKey()]);

        $role->delete();

        $this->assertSame(0, OidcRoleMapping::query()->count());
    }

    #[Test]
    public function discovery_fills_in_the_endpoints_without_saving_them(): void
    {
        Http::fake([
            'https://sso.example.test/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://sso.example.test',
                'authorization_endpoint' => 'https://sso.example.test/authorize',
                'token_endpoint' => 'https://sso.example.test/token',
                'userinfo_endpoint' => 'https://sso.example.test/userinfo',
                'jwks_uri' => 'https://sso.example.test/jwks',
                'scopes_supported' => ['openid', 'profile', 'email', 'groups'],
            ]),
        ]);

        $this->actingAs($this->settingsAdmin())
            ->postJson(route('api.settings.oidc.discover'), ['discovery_url' => 'https://sso.example.test'])
            ->assertOk()
            ->assertJsonPath('discovery.token_endpoint', 'https://sso.example.test/token')
            ->assertJsonPath('discovery.scopes_supported.3', 'groups');

        // The administrator gets to look at what came back and change it before
        // anything is committed: an IdP behind split-horizon DNS advertises
        // endpoints this host cannot reach.
        $this->assertNull(OidcSettings::current()->token_endpoint);
    }

    #[Test]
    public function a_discovery_document_that_is_missing_endpoints_is_rejected(): void
    {
        Http::fake([
            '*' => Http::response(['issuer' => 'https://sso.example.test']),
        ]);

        $this->actingAs($this->settingsAdmin())
            ->postJson(route('api.settings.oidc.discover'), ['discovery_url' => 'https://sso.example.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('discovery_url');
    }

    #[Test]
    public function an_unreachable_provider_is_reported_rather_than_thrown(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $this->actingAs($this->settingsAdmin())
            ->postJson(route('api.settings.oidc.discover'), ['discovery_url' => 'https://sso.example.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('discovery_url');
    }

    #[Test]
    public function the_bootstrap_payload_says_whether_this_account_may_change_it(): void
    {
        $this->actingAs($this->settingsAdmin())
            ->getJson(route('api.bootstrap'))
            ->assertOk()
            ->assertJsonPath('can.manage_settings', true);

        $this->actingAs($this->userWithPermissions([Permissions::USERS_MANAGE]))
            ->getJson(route('api.bootstrap'))
            ->assertOk()
            ->assertJsonPath('can.manage_settings', false);
    }

    #[Test]
    public function the_openid_scope_cannot_be_edited_away(): void
    {
        $settings = new OidcSettings(['scopes' => 'profile email']);

        $this->assertContains('openid', $settings->scopeList());
    }

    #[Test]
    public function rolling_back_refuses_while_passwordless_accounts_exist(): void
    {
        /*
         * `up()` made users.password nullable, so `down()` has to put that
         * contract back — and there is nothing safe to write into the column for
         * an account provisioned by single sign-on. Inventing a password would
         * leave an account whose password nobody knows but which the login form
         * will happily try; deleting the account would take its deployment
         * history with it. So the rollback stops and names what is in the way.
         */
        User::factory()->create(['password' => null]);

        $migration = require database_path('migrations/2026_07_29_090000_create_oidc_tables.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot roll back');

        $migration->down();
    }

    private function settingsAdmin(): User
    {
        return $this->userWithPermissions([Permissions::SETTINGS_MANAGE]);
    }
}
