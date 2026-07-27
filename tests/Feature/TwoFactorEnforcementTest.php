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
