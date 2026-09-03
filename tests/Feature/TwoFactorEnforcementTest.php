<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Section 3.5.1: this portal can push code to 700+ wikis' production servers, so a
 * password alone is not enough for any account that can change production.
 *
 * The requirement applies to the SPA shell and to the API behind it. The API cannot
 * follow a redirect to an HTML page, so it is told outright — but it is never simply
 * allowed through.
 */
final class TwoFactorEnforcementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_read_only_account_is_not_forced_to_enrol(): void
    {
        $user = $this->userWithPermissions([], twoFactor: false);

        $this->actingAs($user)->get('/')->assertOk();
        $this->actingAs($user)->getJson(route('api.bootstrap'))->assertOk();
    }

    #[Test]
    public function an_account_with_deploy_permissions_is_redirected_to_enrolment(): void
    {
        $user = $this->userWithPermissions([Permissions::DEPLOY_EXTENSION], twoFactor: false);

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect(route('two-factor.setup'));

        $this->actingAs($user)
            ->get('/deployments')
            ->assertRedirect(route('two-factor.setup'));
    }

    #[Test]
    public function the_api_refuses_an_unenrolled_account_rather_than_redirecting_it(): void
    {
        $user = $this->userWithPermissions([Permissions::DEPLOY_EXTENSION], twoFactor: false);

        $this->actingAs($user)
            ->getJson(route('api.deployments.index'))
            ->assertForbidden()
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonPath('enrol_url', route('two-factor.setup'));
    }

    #[Test]
    public function the_enrolment_screen_itself_stays_reachable(): void
    {
        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_CORE], twoFactor: false))
            ->get(route('two-factor.setup'))
            ->assertOk()
            ->assertSee('required for this account');
    }

    #[Test]
    public function signing_out_stays_reachable_without_enrolment(): void
    {
        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_CORE], twoFactor: false))
            ->post(route('logout'))
            ->assertRedirect();

        $this->assertGuest();
    }

    #[Test]
    public function an_enrolled_account_is_not_redirected(): void
    {
        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_EXTENSION], twoFactor: true))
            ->get('/')
            ->assertOk();
    }

    #[Test]
    public function every_permission_that_can_change_production_requires_two_factor(): void
    {
        foreach (Permissions::requiringTwoFactor() as $permission) {
            $user = $this->userWithPermissions([$permission], twoFactor: false);

            $this->assertTrue(
                $user->requiresTwoFactor(),
                "[{$permission}] should require two-factor enrolment.",
            );
        }
    }

    #[Test]
    public function guests_are_sent_to_the_login_screen(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/deployments')->assertRedirect(route('login'));
        $this->get('/repositories')->assertRedirect(route('login'));
    }

    #[Test]
    public function a_guest_hitting_the_api_is_refused(): void
    {
        // The SPA reloads on a 401, which hands the request to Laravel and lands on
        // the sign-in page — the one flow that is deliberately still server-rendered.
        $this->getJson(route('api.bootstrap'))->assertUnauthorized();
        $this->postJson(route('api.deployments.store'), [])->assertUnauthorized();
    }

    #[Test]
    public function an_account_linked_to_the_provider_is_not_forced_to_enrol(): void
    {
        /*
         * The second factor is the provider's to enforce for these accounts, and
         * asking someone to carry a second authenticator for one sign-in buys
         * nothing. What it does buy is a console its own SSO accounts cannot
         * reach.
         */
        $user = $this->userWithPermissions([Permissions::DEPLOY_CORE], twoFactor: false);
        $user->forceFill(['password' => null, 'oidc_subject' => 'sso-1'])->save();

        $this->assertFalse($user->fresh()->requiresTwoFactor());

        $this->actingAs($user->fresh())->get('/')->assertOk();
        $this->actingAs($user->fresh())->get('/deployments')->assertOk();
        $this->actingAs($user->fresh())->getJson(route('api.deployments.index'))->assertOk();
    }

    #[Test]
    public function a_linked_account_that_kept_its_password_is_exempt_too(): void
    {
        /*
         * The common case, and the one the first pass got wrong: an account an
         * administrator created locally and then signed into with the provider.
         * The exemption follows the account, not the session.
         *
         * The cost is real and deliberate — that password is a way in the
         * provider never sees, so its MFA is not on that path. Switching password
         * sign-in off is what closes it.
         */
        $user = $this->userWithPermissions([Permissions::DEPLOY_CORE], twoFactor: false);
        $user->forceFill(['oidc_subject' => 'sso-2'])->save();

        $this->assertFalse($user->fresh()->requiresTwoFactor());

        $this->actingAs($user->fresh())->get('/')->assertOk();
    }

    #[Test]
    public function a_local_account_with_no_provider_link_must_still_enrol(): void
    {
        // The requirement is unchanged for accounts the provider knows nothing
        // about, which is every account on an install without single sign-on.
        $user = $this->userWithPermissions([Permissions::DEPLOY_CORE], twoFactor: false);

        $this->assertTrue($user->fresh()->requiresTwoFactor());

        $this->actingAs($user->fresh())->get('/')->assertRedirect(route('two-factor.setup'));
    }

    #[Test]
    public function an_account_with_no_password_can_still_enrol(): void
    {
        /*
         * Not required to, but not prevented from either. Enrolment is behind
         * Fortify's password confirmation, and an account provisioned by single
         * sign-on has no password to confirm — so the two-factor screen the
         * account menu offers everyone was a dead end for exactly those accounts.
         *
         * A passwordless account is therefore confirmed by virtue of the session
         * the identity provider already gave it.
         */
        $user = $this->userWithPermissions([Permissions::DEPLOY_CORE], twoFactor: false);
        $user->forceFill(['password' => null, 'oidc_subject' => 'sso-1'])->save();

        $this->actingAs($user)->get(route('two-factor.setup'))->assertOk();

        $this->actingAs($user)
            ->post('/user/two-factor-authentication')
            ->assertRedirect();

        $this->assertNotNull($user->fresh()->two_factor_secret);
    }

    #[Test]
    public function an_account_with_a_password_still_has_to_confirm_it_to_enrol(): void
    {
        // The exemption is for accounts that have no password, not for everyone.
        $user = $this->userWithPermissions([Permissions::DEPLOY_CORE], twoFactor: false);

        $this->actingAs($user)
            ->post('/user/two-factor-authentication')
            ->assertRedirect(route('password.confirm'));

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    #[Test]
    public function there_is_no_self_registration_route(): void
    {
        // Accounts are created by someone holding users.manage, or by
        // `php artisan mwdeploy:create-user`.
        $this->assertFalse(
            app('router')->has('register'),
            'Self-registration must stay disabled on a tool that deploys to production.',
        );
    }
}
