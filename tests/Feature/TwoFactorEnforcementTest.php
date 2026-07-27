<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Section 3.5.1: this portal can push code to 700+ wikis' production servers, so
 * a password alone is not enough for any account that can change production.
 */
final class TwoFactorEnforcementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_read_only_account_is_not_forced_to_enrol(): void
    {
        $this->actingAs($this->userWithPermissions([], twoFactor: false))
            ->get(route('dashboard'))
            ->assertOk();
    }

    #[Test]
    public function an_account_with_deploy_permissions_is_redirected_to_enrolment(): void
    {
        $user = $this->userWithPermissions([Permissions::DEPLOY_EXTENSION], twoFactor: false);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('two-factor.setup'));

        $this->actingAs($user)
            ->get(route('deployments.index'))
            ->assertRedirect(route('two-factor.setup'));
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
            ->get(route('dashboard'))
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
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('deployments.index'))->assertRedirect(route('login'));
        $this->get(route('repositories.index'))->assertRedirect(route('login'));
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
