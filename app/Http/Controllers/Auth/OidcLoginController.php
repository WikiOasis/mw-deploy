<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\OidcSettings;
use App\Services\Oidc\OidcClient;
use App\Services\Oidc\OidcException;
use App\Services\Oidc\OidcIdentity;
use App\Services\Oidc\OidcUserProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Sign-in against the configured OpenID Connect provider.
 *
 * Two endpoints, both server-rendered redirects, deliberately alongside Fortify's
 * own sign-in rather than replacing it: an ops tool that can only be entered
 * through a third party is an ops tool you cannot get into on the day the third
 * party is what broke. Local passwords stay as the way back in.
 *
 * The session carries the state, the nonce and the PKCE verifier between the two
 * requests, and all three are cleared the moment the callback reads them, so a
 * replayed callback URL has nothing left to match against.
 */
final class OidcLoginController extends Controller
{
    /** Where the flow's one-request state lives. */
    private const SESSION_KEY = 'oidc.flow';

    public function __construct(
        private readonly OidcClient $client,
        private readonly OidcUserProvisioner $provisioner,
    ) {}

    /**
     * Start the flow: build the authorisation URL and send the browser to the IdP.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $settings = OidcSettings::current();

        if (! $settings->isUsable()) {
            return $this->refuse('Single sign-on is not configured on this console.');
        }

        $flow = $this->client->authorizationRequest($settings, route('oidc.callback'));

        $request->session()->put(self::SESSION_KEY, [
            'state' => $flow['state'],
            'nonce' => $flow['nonce'],
            'verifier' => $flow['verifier'],
            // Where they were going before they were bounced to sign-in, kept
            // here rather than relying on Laravel's intended URL surviving the
            // round trip through the IdP.
            'intended' => $request->session()->pull('url.intended'),
        ]);

        return redirect()->away($flow['url']);
    }

    /**
     * Come back from the IdP: check the state, exchange the code, and sign in.
     */
    public function callback(Request $request): RedirectResponse
    {
        $flow = $request->session()->pull(self::SESSION_KEY);

        if (! is_array($flow) || ! is_string($flow['state'] ?? null)) {
            return $this->refuse('That sign-in link has expired. Try again.');
        }

        if ($request->filled('error')) {
            Log::warning('OIDC provider returned an error', [
                'error' => $request->string('error')->toString(),
                'description' => $request->string('error_description')->toString(),
            ]);

            return $this->refuse('The identity provider refused the sign-in attempt.');
        }

        /*
         * The state check is the CSRF protection for this endpoint: without it,
         * an attacker's authorisation code could be walked through this callback
         * in someone else's browser, signing them into the attacker's identity.
         */
        if (! hash_equals((string) $flow['state'], (string) $request->query('state'))) {
            return $this->refuse('That sign-in attempt did not match this browser session. Try again.');
        }

        $code = (string) $request->query('code');

        if ($code === '') {
            return $this->refuse('The identity provider did not return an authorisation code.');
        }

        $settings = OidcSettings::current();

        if (! $settings->isUsable()) {
            return $this->refuse('Single sign-on is not configured on this console.');
        }

        try {
            $tokens = $this->client->exchange(
                $settings,
                $code,
                route('oidc.callback'),
                (string) $flow['verifier'],
                (string) $flow['nonce'],
            );

            $identity = OidcIdentity::fromClaims(
                $this->withUserinfo($settings, $tokens['claims'], $tokens['access_token']),
                $settings,
            );

            $user = $this->provisioner->resolve($identity, $settings);
        } catch (OidcException $exception) {
            Log::warning('OIDC sign-in failed', ['reason' => $exception->logContext]);

            return $this->refuse($exception->getMessage());
        }

        // No "remember me": the IdP owns session length, and a long-lived cookie
        // on a console that can deploy to production would outlive whatever the
        // IdP decided about how long this person stays signed in.
        Auth::login($user);

        // Fresh session id on privilege change, as with any other sign-in.
        $request->session()->regenerate();

        Log::info('OIDC sign-in', ['user_id' => $user->getKey(), 'email' => $user->email]);

        $intended = is_string($flow['intended'] ?? null) ? $flow['intended'] : null;

        return redirect()->to($intended ?? '/');
    }

    /**
     * The ID token's claims, topped up from userinfo when the groups claim is not
     * in the token.
     *
     * Several providers — Authentik and Okta among them — will only release group
     * membership from userinfo, so a console that read the token alone would see
     * every SSO account arrive with no roles. userinfo's `sub` must match the
     * token's: a mismatch means the two are describing different people, and the
     * spec says to reject it rather than merge it.
     *
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     */
    private function withUserinfo(OidcSettings $settings, array $claims, ?string $accessToken): array
    {
        $groupsClaim = (string) ($settings->groups_claim ?: 'groups');

        if ($accessToken === null || data_get($claims, $groupsClaim) !== null) {
            return $claims;
        }

        $extra = $this->client->userinfo($settings, $accessToken);

        if ($extra === []) {
            return $claims;
        }

        if (($extra['sub'] ?? null) !== ($claims['sub'] ?? null)) {
            Log::warning('OIDC userinfo described a different subject than the id_token; ignoring it');

            return $claims;
        }

        // The token wins on every claim it carries: it is signed, userinfo is
        // not, and only the claims actually missing are being filled in here.
        return [...$extra, ...$claims];
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['oidc' => $message]);
    }
}
