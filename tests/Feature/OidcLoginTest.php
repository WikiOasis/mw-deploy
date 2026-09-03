<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OidcRoleMapping;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeIdentityProvider;
use Tests\TestCase;

/**
 * Signing in through a third-party OpenID Connect provider.
 *
 * The point of the flow is that the console believes the IdP about who someone is
 * and what groups they are in — so most of these tests are about the cases where
 * it must *not* believe what it was handed: a token signed by the wrong key, one
 * minted for another client, one replayed from another browser's session, and an
 * unverified email being used to walk into an account that already exists.
 */
final class OidcLoginTest extends TestCase
{
    use RefreshDatabase;

    private FakeIdentityProvider $idp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idp = new FakeIdentityProvider;

        // The key set is cached for an hour in production; between tests it must
        // not be, or a test's own JWKS answer would be the previous test's.
        Cache::flush();
    }

    /*
     * -----------------------------------------------------------------------
     * The sign-in page
     * -----------------------------------------------------------------------
     */

    #[Test]
    public function the_sign_in_page_offers_nothing_until_a_provider_is_configured(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Sign in with')
            ->assertSee('Password');
    }

    #[Test]
    public function the_sign_in_page_offers_the_provider_once_it_is_configured(): void
    {
        $this->idp->configure(['label' => 'Example SSO']);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Sign in with Example SSO')
            // The password form stays: an ops console that can only be entered
            // through a third party is one you cannot enter when it is down.
            ->assertSee('Password');
    }

    #[Test]
    public function a_disabled_provider_is_not_offered_and_cannot_be_started(): void
    {
        $this->idp->configure(['enabled' => false]);

        $this->get(route('login'))->assertDontSee('Sign in with');

        $this->get(route('oidc.redirect'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('oidc');
    }

    /*
     * -----------------------------------------------------------------------
     * Starting the flow
     * -----------------------------------------------------------------------
     */

    #[Test]
    public function starting_the_flow_sends_the_browser_to_the_provider_with_pkce(): void
    {
        $this->idp->configure();

        $response = $this->get(route('oidc.redirect'));

        $response->assertRedirectContains(FakeIdentityProvider::ISSUER.'/authorize');

        $query = $this->authorizationQuery($response->headers->get('Location'));

        $this->assertSame('code', $query['response_type']);
        $this->assertSame(FakeIdentityProvider::CLIENT_ID, $query['client_id']);
        $this->assertSame(route('oidc.callback'), $query['redirect_uri']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);
        $this->assertNotEmpty($query['state']);
        $this->assertNotEmpty($query['nonce']);

        // openid is always requested, and the groups scope this install asked
        // for is passed through.
        $this->assertStringContainsString('openid', $query['scope']);
        $this->assertStringContainsString('groups', $query['scope']);
    }

    #[Test]
    public function the_openid_scope_is_always_requested_even_if_it_was_edited_out(): void
    {
        $this->idp->configure(['scopes' => 'profile email']);

        $query = $this->authorizationQuery(
            $this->get(route('oidc.redirect'))->headers->get('Location')
        );

        $this->assertStringContainsString('openid', $query['scope']);
    }

    /*
     * -----------------------------------------------------------------------
     * Coming back
     * -----------------------------------------------------------------------
     */

    #[Test]
    public function a_first_sign_in_provisions_an_account_and_maps_its_groups_to_roles(): void
    {
        $settings = $this->idp->configure();
        $deployers = Role::factory()->create(['name' => 'mediawiki-admins']);
        $viewers = Role::factory()->create(['name' => 'viewer']);

        OidcRoleMapping::create(['group' => 'mediawiki-admins', 'role_id' => $deployers->getKey()]);
        OidcRoleMapping::create(['group' => 'unrelated-group', 'role_id' => $viewers->getKey()]);

        $this->signIn([
            'sub' => 'subject-42',
            'email' => 'nadia@wikioasis.org',
            'email_verified' => true,
            'name' => 'Nadia',
            'groups' => ['mediawiki-admins', 'everyone'],
        ])->assertRedirect('/');

        $user = User::query()->where('oidc_subject', 'subject-42')->sole();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('nadia@wikioasis.org', $user->email);
        $this->assertSame('Nadia', $user->name);
        $this->assertNotNull($user->email_verified_at);
        // No password at all, rather than one nobody knows.
        $this->assertNull($user->password);

        $this->assertSame(['mediawiki-admins'], $user->roles->pluck('name')->all());
        $this->assertNotNull($settings->fresh()->id);
    }

    #[Test]
    public function group_names_are_matched_regardless_of_case(): void
    {
        $this->idp->configure();
        $role = Role::factory()->create(['name' => 'ops']);
        OidcRoleMapping::create(['group' => 'Ops', 'role_id' => $role->getKey()]);

        $this->signIn([
            'email' => 'case@wikioasis.org',
            'email_verified' => true,
            'groups' => ['OPS'],
        ]);

        $this->assertSame(['ops'], User::query()->sole()->roles->pluck('name')->all());
    }

    #[Test]
    public function groups_are_read_from_userinfo_when_the_token_does_not_carry_them(): void
    {
        // Authentik and Okta both behave this way: the ID token has no groups
        // claim and userinfo does.
        $this->idp->configure();
        $role = Role::factory()->create(['name' => 'ops']);
        OidcRoleMapping::create(['group' => 'ops', 'role_id' => $role->getKey()]);

        $this->signIn(
            ['sub' => 'subject-7', 'email' => 'from-userinfo@wikioasis.org', 'email_verified' => true],
            userinfo: ['sub' => 'subject-7', 'groups' => ['ops']],
        );

        $this->assertSame(['ops'], User::query()->sole()->roles->pluck('name')->all());
    }

    #[Test]
    public function userinfo_describing_a_different_subject_is_ignored(): void
    {
        $this->idp->configure();
        $role = Role::factory()->create(['name' => 'ops']);
        OidcRoleMapping::create(['group' => 'ops', 'role_id' => $role->getKey()]);

        $this->signIn(
            ['sub' => 'subject-7', 'email' => 'mismatch@wikioasis.org', 'email_verified' => true],
            userinfo: ['sub' => 'somebody-else', 'groups' => ['ops']],
        );

        // Signed in — the token itself was fine — but granted nothing, because
        // the group claim came from a response about another person.
        $this->assertAuthenticated();
        $this->assertSame([], User::query()->sole()->roles->pluck('name')->all());
    }

    #[Test]
    public function a_nested_groups_claim_can_be_named_with_dot_notation(): void
    {
        $this->idp->configure(['groups_claim' => 'resource_access.console.roles']);
        $role = Role::factory()->create(['name' => 'ops']);
        OidcRoleMapping::create(['group' => 'ops', 'role_id' => $role->getKey()]);

        $this->signIn([
            'email' => 'nested@wikioasis.org',
            'email_verified' => true,
            'resource_access' => ['console' => ['roles' => ['ops']]],
        ]);

        $this->assertSame(['ops'], User::query()->sole()->roles->pluck('name')->all());
    }

    #[Test]
    public function a_second_sign_in_reuses_the_account_the_subject_already_owns(): void
    {
        $this->idp->configure();

        $this->signIn(['sub' => 'subject-9', 'email' => 'repeat@wikioasis.org', 'email_verified' => true]);
        $this->post(route('logout'));

        // Same subject, new email address at the IdP: still the same account.
        $this->signIn(['sub' => 'subject-9', 'email' => 'renamed@wikioasis.org', 'email_verified' => true]);

        $this->assertSame(1, User::query()->count());
        $this->assertSame('renamed@wikioasis.org', User::query()->sole()->email);
    }

    #[Test]
    public function an_existing_account_is_linked_on_a_verified_email_and_keeps_its_roles(): void
    {
        $this->idp->configure();

        $existing = $this->userWithPermissions([Permissions::DEPLOY_CORE]);

        $this->signIn(['sub' => 'subject-11', 'email' => $existing->email, 'email_verified' => true]);

        $existing->refresh();

        $this->assertAuthenticatedAs($existing);
        $this->assertSame('subject-11', $existing->oidc_subject);
        // Nothing was mapped, so the account keeps the roles it had.
        $this->assertTrue($existing->fresh()->hasPermission(Permissions::DEPLOY_CORE));
        $this->assertSame(1, User::query()->count());
    }

    #[Test]
    public function an_unverified_email_cannot_claim_an_existing_account(): void
    {
        $this->idp->configure();

        $existing = $this->userWithPermissions([Permissions::DEPLOY_CORE]);

        $this->signIn(['sub' => 'attacker', 'email' => $existing->email, 'email_verified' => false])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('oidc');

        $this->assertGuest();
        $this->assertNull($existing->fresh()->oidc_subject);
    }

    #[Test]
    public function account_creation_can_be_switched_off(): void
    {
        $this->idp->configure(['create_users' => false]);

        $this->signIn(['email' => 'stranger@wikioasis.org', 'email_verified' => true])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('oidc');

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    #[Test]
    public function sign_in_can_be_restricted_to_certain_groups(): void
    {
        $this->idp->configure(['allowed_groups' => ['console-users']]);

        $this->signIn(['email' => 'outsider@wikioasis.org', 'email_verified' => true, 'groups' => ['some-other-team']])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('oidc');

        $this->assertGuest();

        $this->signIn(['email' => 'insider@wikioasis.org', 'email_verified' => true, 'groups' => ['console-users']]);

        $this->assertAuthenticated();
    }

    #[Test]
    public function revoking_a_group_at_the_provider_revokes_the_role_here(): void
    {
        $this->idp->configure(['sync_roles' => true]);
        $role = Role::factory()->create(['name' => 'ops']);
        OidcRoleMapping::create(['group' => 'ops', 'role_id' => $role->getKey()]);

        $this->signIn(['sub' => 's1', 'email' => 'demoted@wikioasis.org', 'email_verified' => true, 'groups' => ['ops']]);
        $this->assertSame(['ops'], User::query()->sole()->roles->pluck('name')->all());

        $this->post(route('logout'));

        $this->signIn(['sub' => 's1', 'email' => 'demoted@wikioasis.org', 'email_verified' => true, 'groups' => []]);

        $this->assertSame([], User::query()->sole()->fresh(['roles'])->roles->pluck('name')->all());
    }

    #[Test]
    public function roles_are_left_alone_when_re_applying_the_mapping_is_switched_off(): void
    {
        $this->idp->configure(['sync_roles' => false]);
        $role = Role::factory()->create(['name' => 'ops']);
        $extra = Role::factory()->create(['name' => 'granted-by-hand']);
        OidcRoleMapping::create(['group' => 'ops', 'role_id' => $role->getKey()]);

        $this->signIn(['sub' => 's2', 'email' => 'kept@wikioasis.org', 'email_verified' => true, 'groups' => ['ops']]);

        $user = User::query()->sole();
        $user->roles()->attach($extra);
        $this->post(route('logout'));

        $this->signIn(['sub' => 's2', 'email' => 'kept@wikioasis.org', 'email_verified' => true, 'groups' => []]);

        $this->assertEqualsCanonicalizing(
            ['ops', 'granted-by-hand'],
            $user->fresh(['roles'])->roles->pluck('name')->all(),
        );
    }

    #[Test]
    public function an_empty_mapping_never_changes_anybodys_roles(): void
    {
        // Switching SSO on before writing the mapping must not strip every role
        // from the first administrator who signs in through it.
        $this->idp->configure(['sync_roles' => true]);

        $existing = $this->userWithPermissions([Permissions::USERS_MANAGE]);

        $this->signIn(['sub' => 'admin-1', 'email' => $existing->email, 'email_verified' => true]);

        $this->assertTrue($existing->fresh()->hasPermission(Permissions::USERS_MANAGE));
    }

    /*
     * -----------------------------------------------------------------------
     * What must not be believed
     * -----------------------------------------------------------------------
     */

    #[Test]
    public function a_token_signed_by_an_unknown_key_is_refused(): void
    {
        $this->idp->configure();

        $this->signIn(
            ['email' => 'forged@wikioasis.org', 'email_verified' => true],
            idToken: fn (string $nonce): string => $this->idp->idTokenFromAnotherKey([
                'nonce' => $nonce,
                'email' => 'forged@wikioasis.org',
                'email_verified' => true,
            ]),
        )->assertSessionHasErrors('oidc');

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    #[Test]
    public function an_unsigned_token_is_refused(): void
    {
        $this->idp->configure();

        $this->signIn(
            ['email' => 'none-alg@wikioasis.org'],
            idToken: fn (string $nonce): string => $this->idp->idToken(['nonce' => $nonce], algorithm: 'none'),
        )->assertSessionHasErrors('oidc');

        $this->assertGuest();
    }

    #[Test]
    public function a_token_from_another_issuer_is_refused(): void
    {
        $this->idp->configure();

        $this->signIn(['iss' => 'https://evil.example.test', 'email' => 'a@wikioasis.org'])
            ->assertSessionHasErrors('oidc');

        $this->assertGuest();
    }

    #[Test]
    public function a_token_minted_for_another_client_is_refused(): void
    {
        $this->idp->configure();

        $this->signIn(['aud' => 'some-other-client', 'email' => 'a@wikioasis.org'])
            ->assertSessionHasErrors('oidc');

        $this->assertGuest();
    }

    #[Test]
    public function a_multi_audience_token_must_name_this_client_as_the_authorised_party(): void
    {
        $this->idp->configure();

        $this->signIn([
            'aud' => [FakeIdentityProvider::CLIENT_ID, 'another-client'],
            'azp' => 'another-client',
            'email' => 'a@wikioasis.org',
        ])->assertSessionHasErrors('oidc');

        $this->assertGuest();
    }

    #[Test]
    public function an_expired_token_is_refused(): void
    {
        $this->idp->configure();

        $this->signIn(['exp' => time() - 3600, 'email' => 'a@wikioasis.org'])
            ->assertSessionHasErrors('oidc');

        $this->assertGuest();
    }

    #[Test]
    public function a_token_whose_nonce_does_not_match_this_session_is_refused(): void
    {
        // A token captured from someone else's sign-in, replayed here.
        $this->idp->configure();

        $this->idp->fakeEndpoints([
            'nonce' => 'a-nonce-from-another-session',
            'email' => 'replay@wikioasis.org',
            'email_verified' => true,
        ]);

        $state = $this->startFlow();

        $this->get(route('oidc.callback', ['code' => 'a-code', 'state' => $state]))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('oidc');

        $this->assertGuest();
    }

    #[Test]
    public function a_callback_whose_state_does_not_match_is_refused(): void
    {
        $this->idp->configure();
        $this->startFlow();

        $this->get(route('oidc.callback', ['code' => 'a-code', 'state' => 'not-the-state-we-sent']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('oidc');

        $this->assertGuest();
        // Nothing was exchanged: the state check happens before any outbound call.
        Http::assertNothingSent();
    }

    #[Test]
    public function a_callback_with_no_flow_in_the_session_is_refused(): void
    {
        $this->idp->configure();

        $this->get(route('oidc.callback', ['code' => 'a-code', 'state' => 'anything']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('oidc');

        $this->assertGuest();
    }

    #[Test]
    public function the_flow_state_is_single_use(): void
    {
        $this->idp->configure();

        $this->idp->fakeEndpoints(['email' => 'once@wikioasis.org', 'email_verified' => true]);

        $state = $this->startFlow();

        // Replaying the same callback URL finds nothing left to match against.
        $this->get(route('oidc.callback', ['code' => 'a-code', 'state' => $state]));
        $this->post(route('logout'));

        $this->get(route('oidc.callback', ['code' => 'a-code', 'state' => $state]))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('oidc');

        $this->assertGuest();
    }

    #[Test]
    public function a_provider_error_comes_back_to_the_sign_in_page(): void
    {
        $this->idp->configure();

        $state = $this->startFlow();

        $this->get(route('oidc.callback', ['error' => 'access_denied', 'state' => $state]))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('oidc');

        $this->assertGuest();
    }

    /*
     * -----------------------------------------------------------------------
     * Password sign-in alongside it
     * -----------------------------------------------------------------------
     */

    #[Test]
    public function an_account_provisioned_by_single_sign_on_cannot_be_entered_with_a_password(): void
    {
        $this->idp->configure();

        $this->signIn(['sub' => 'no-password', 'email' => 'sso-only@wikioasis.org', 'email_verified' => true]);
        $this->post(route('logout'));

        $this->post(route('login.store'), ['email' => 'sso-only@wikioasis.org', 'password' => ''])
            ->assertSessionHasErrors();

        $this->assertGuest();
    }

    #[Test]
    public function a_password_account_still_signs_in_with_its_password(): void
    {
        $this->idp->configure();

        $user = User::factory()->create([
            'email' => 'local@wikioasis.org',
            'password' => Hash::make('a-very-long-password'),
        ]);

        $this->post(route('login.store'), [
            'email' => 'local@wikioasis.org',
            'password' => 'a-very-long-password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    /*
     * -----------------------------------------------------------------------
     * Helpers
     * -----------------------------------------------------------------------
     */

    /**
     * Walk the whole flow: start it, mint a token carrying this session's nonce,
     * and come back through the callback.
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>|null  $userinfo
     * @param  (callable(string): string)|null  $idToken  mint the token yourself, given the nonce
     */
    private function signIn(array $claims, ?array $userinfo = null, ?callable $idToken = null): TestResponse
    {
        $location = $this->get(route('oidc.redirect'))->headers->get('Location');
        $query = $this->authorizationQuery($location);

        $claims = ['nonce' => $query['nonce'], ...$claims];

        $this->idp->fakeEndpoints(
            $claims,
            $userinfo,
            $idToken === null ? null : $idToken($query['nonce']),
        );

        return $this->get(route('oidc.callback', ['code' => 'an-authorisation-code', 'state' => $query['state']]));
    }

    /** Start the flow and return the state it put in the session. */
    private function startFlow(): string
    {
        return $this->authorizationQuery(
            $this->get(route('oidc.redirect'))->headers->get('Location')
        )['state'];
    }

    /**
     * @return array<string, string>
     */
    private function authorizationQuery(?string $location): array
    {
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

        return array_map(static fn ($value): string => (string) $value, $query);
    }
}
