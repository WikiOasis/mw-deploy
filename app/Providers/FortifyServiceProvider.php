<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\OidcSettings;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        /*
         * Password sign-in, with one addition to Fortify's default: an account
         * provisioned by single sign-on has no password at all, and no password
         * is not a password to check credentials against. Refusing outright is
         * clearer than trusting a hash comparison against an empty column, and
         * it means a provisioned account cannot be entered by guessing.
         */
        Fortify::authenticateUsing(function (Request $request): ?User {
            $field = Fortify::username();
            $identifier = (string) $request->input($field);

            if (config('fortify.lowercase_usernames')) {
                $identifier = Str::lower($identifier);
            }

            $user = User::query()->where($field, $identifier)->first();

            /*
             * An install with single sign-on working may switch the password form
             * off entirely. Refused here rather than only hidden on the page: the
             * endpoint stays registered, so a hidden form is not a closed door.
             *
             * `passwordLoginAllowed()` is what keeps that from locking anyone out
             * — it answers true whenever SSO is not usable, and the environment
             * carries a break-glass override.
             */
            if (! OidcSettings::current()->passwordLoginAllowed()) {
                return null;
            }

            /*
             * The one addition to Fortify's own check. Everything else is
             * delegated to the guard's user provider rather than reimplemented,
             * so credential verification and the rehash-on-login behaviour stay
             * whatever the framework does — including honouring
             * `hashing.rehash_on_login` when the work factor is raised.
             */
            if (! $user instanceof User || ! filled($user->password)) {
                return null;
            }

            $provider = Auth::guard(config('fortify.guard'))->getProvider();
            $credentials = ['password' => (string) $request->input('password')];

            if (! $provider->validateCredentials($user, $credentials)) {
                return null;
            }

            if (config('hashing.rehash_on_login', true) && method_exists($provider, 'rehashPasswordIfRequired')) {
                $provider->rehashPasswordIfRequired($user, $credentials);
            }

            return $user;
        });

        /*
         * The sign-in page needs to know whether to offer the single sign-on
         * button, and what to call it. Read per request rather than cached: it
         * changes from the settings screen, and a stale button that points at a
         * provider this console no longer trusts is worse than a query.
         */
        /*
         * Enabling or disabling TOTP is behind password confirmation (see
         * config/fortify.php). An account provisioned by single sign-on has no
         * password to confirm, which made enrolment impossible for exactly the
         * accounts that most need it: grant one a deploy role and RequireTwoFactor
         * then blocks it from everything *except* an enrolment screen it cannot
         * get through. That is a locked-out account, not a secured one.
         *
         * So a passwordless account confirms by virtue of holding the session it
         * already got from the identity provider. Every account that does have a
         * password is confirmed the ordinary way, unchanged.
         */
        Fortify::confirmPasswordsUsing(function ($user, ?string $password): bool {
            if ($user instanceof User && ! filled($user->password)) {
                return true;
            }

            return Hash::check((string) $password, (string) $user->password);
        });

        Fortify::loginView(fn () => view('auth.login', [
            /*
             * `rescue`, because this runs on the one page an operator needs
             * during an upgrade: between deploying the code and running the
             * migration the table does not exist yet, and a sign-in page that
             * 500s in that window is a bad way to find out. No row means no
             * button, which is the correct answer anyway.
             */
            'oidc' => $settings = rescue(fn (): OidcSettings => OidcSettings::current(), new OidcSettings, report: false),
            // Whether to draw the password form at all. The same answer the
            // authentication callback gives, so the page cannot offer a form that
            // would be refused, nor hide one that still works.
            'passwords' => $settings->passwordLoginAllowed(),
        ]));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));
    }
}
