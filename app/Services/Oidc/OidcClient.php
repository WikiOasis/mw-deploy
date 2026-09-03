<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use App\Models\OidcSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The authorisation-code half of the flow: where to send the browser, and what to
 * do with the code it comes back with.
 *
 * Authorisation code with PKCE, S256. PKCE is not optional here even though this
 * is a confidential client with a secret: it costs one hash, and it closes code
 * interception at the redirect — which, on a console reached through an ops
 * proxy, is not a theoretical place for a code to leak.
 */
final class OidcClient
{
    public function __construct(private readonly IdTokenVerifier $verifier) {}

    /**
     * The URL to send the browser to, and the state to keep in the session until
     * it comes back.
     *
     * @return array{url: string, state: string, nonce: string, verifier: string}
     */
    public function authorizationRequest(OidcSettings $settings, string $redirectUri): array
    {
        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(96);

        $query = [
            'response_type' => 'code',
            'client_id' => (string) $settings->client_id,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $settings->scopeList()),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => Base64Url::encode(hash('sha256', $verifier, binary: true)),
            'code_challenge_method' => 'S256',
        ];

        $separator = str_contains((string) $settings->authorization_endpoint, '?') ? '&' : '?';

        return [
            'url' => $settings->authorization_endpoint.$separator.http_build_query($query),
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
        ];
    }

    /**
     * Exchange the code for tokens and return the verified ID token claims.
     *
     * @return array{claims: array<string, mixed>, access_token: string|null}
     *
     * @throws OidcException
     */
    public function exchange(
        OidcSettings $settings,
        string $code,
        string $redirectUri,
        string $verifier,
        string $nonce,
    ): array {
        try {
            /*
             * The secret goes in the body rather than in a Basic header: both
             * are allowed, and client_secret_post is the one every IdP in this
             * family accepts without configuration.
             */
            $response = Http::asForm()->timeout(15)->acceptJson()->post((string) $settings->token_endpoint, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'client_id' => (string) $settings->client_id,
                'client_secret' => (string) $settings->client_secret,
                'code_verifier' => $verifier,
            ]);
        } catch (ConnectionException $exception) {
            throw OidcException::because(
                'Could not reach the identity provider to complete sign-in.',
                'token endpoint unreachable: '.$exception->getMessage(),
            );
        }

        if ($response->failed()) {
            throw OidcException::providerRefused(
                'token endpoint returned '.$response->status().' '.(string) $response->json('error'),
            );
        }

        $idToken = $response->json('id_token');

        if (! is_string($idToken) || $idToken === '') {
            throw OidcException::because(
                'The identity provider completed sign-in without saying who signed in.',
                'token response carried no id_token',
            );
        }

        $accessToken = $response->json('access_token');

        return [
            'claims' => $this->verifier->verify($idToken, $settings, $nonce),
            'access_token' => is_string($accessToken) && $accessToken !== '' ? $accessToken : null,
        ];
    }

    /**
     * The userinfo endpoint's claims.
     *
     * Only called when the ID token did not carry what is needed — most often the
     * groups claim, which several IdPs will only release from userinfo. A
     * userinfo call that fails is not fatal on its own; the caller decides,
     * because whether the missing claim matters depends on what it was.
     *
     * @return array<string, mixed>
     */
    public function userinfo(OidcSettings $settings, string $accessToken): array
    {
        if (! filled($settings->userinfo_endpoint)) {
            return [];
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(15)
                ->acceptJson()
                ->get((string) $settings->userinfo_endpoint);
        } catch (ConnectionException) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $claims = $response->json();

        return is_array($claims) ? $claims : [];
    }
}
