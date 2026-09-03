<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\OidcSettings;
use App\Services\Oidc\Base64Url;
use Illuminate\Support\Facades\Http;

/**
 * A stand-in OpenID Connect provider for the sign-in tests.
 *
 * It holds a real RSA key and mints real signed ID tokens, so the tests exercise
 * the actual signature check rather than a stubbed one — which is the whole point
 * of testing this flow at all. The key is generated once per process because
 * generating one costs more than every other assertion in the suite put together.
 */
final class FakeIdentityProvider
{
    public const ISSUER = 'https://sso.example.test/application/o/console';

    public const CLIENT_ID = 'wikioasis-console';

    public const KEY_ID = 'test-key';

    /** @var array{private: \OpenSSLAsymmetricKey, jwk: array<string, mixed>}|null */
    private static ?array $key = null;

    /** @var array<string, mixed> the claims the token endpoint will mint next */
    private array $claims = [];

    /** @var array<string, mixed>|null */
    private ?array $userinfo = null;

    /** A token minted by the test itself, instead of a well-formed one. */
    private ?string $idTokenOverride = null;

    private bool $registered = false;

    /**
     * The settings row an install would have after configuring this provider.
     */
    public function configure(array $overrides = []): OidcSettings
    {
        $settings = OidcSettings::current();

        $settings->fill([
            'enabled' => true,
            'label' => 'Example SSO',
            'issuer' => self::ISSUER,
            'client_id' => self::CLIENT_ID,
            'client_secret' => 'a-client-secret',
            'authorization_endpoint' => self::ISSUER.'/authorize',
            'token_endpoint' => self::ISSUER.'/token',
            'userinfo_endpoint' => self::ISSUER.'/userinfo',
            'jwks_uri' => self::ISSUER.'/jwks',
            'scopes' => 'openid profile email groups',
            'groups_claim' => 'groups',
            ...$overrides,
        ]);

        $settings->save();

        return $settings->refresh();
    }

    /**
     * Answer the token, JWKS and userinfo endpoints.
     *
     * The stubs are registered once and read the fields below when the request
     * actually arrives, rather than being re-registered per call: the HTTP fake
     * accumulates stubs and the *first* one registered for a URL is the one that
     * answers, so a test that signs in twice would otherwise be handed its first
     * token — and its first nonce — on the second attempt.
     *
     * @param  array<string, mixed>  $claims  claims to put in the ID token, merged over the defaults
     * @param  array<string, mixed>|null  $userinfo  what /userinfo returns, or null for the subject alone
     */
    public function fakeEndpoints(array $claims, ?array $userinfo = null, ?string $idToken = null): void
    {
        $this->claims = $claims;
        $this->userinfo = $userinfo;
        $this->idTokenOverride = $idToken;

        if ($this->registered) {
            return;
        }

        $this->registered = true;

        Http::fake([
            self::ISSUER.'/jwks' => fn () => Http::response(['keys' => [$this->jwk()]]),
            self::ISSUER.'/token' => fn () => Http::response([
                'token_type' => 'Bearer',
                'access_token' => 'an-access-token',
                'id_token' => $this->idTokenOverride ?? $this->idToken($this->claims),
            ]),
            self::ISSUER.'/userinfo' => fn () => Http::response(
                $this->userinfo ?? ['sub' => $this->claims['sub'] ?? 'subject-1'],
            ),
        ]);
    }

    /**
     * A signed ID token. Claims are merged over a set that would pass every
     * check, so a test names only the claim it is about.
     *
     * @param  array<string, mixed>  $claims
     */
    public function idToken(array $claims, string $algorithm = 'RS256'): string
    {
        $payload = [
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'subject-1',
            'exp' => time() + 300,
            'iat' => time(),
            ...$claims,
        ];

        $header = ['alg' => $algorithm, 'typ' => 'JWT', 'kid' => self::KEY_ID];

        $signed = Base64Url::encode((string) json_encode($header)).'.'.Base64Url::encode((string) json_encode($payload));

        // `none` and the like: hand back the shape without a signature, which is
        // exactly what an attacker would send.
        if ($algorithm !== 'RS256') {
            return $signed.'.'.Base64Url::encode('not-a-signature');
        }

        openssl_sign($signed, $signature, self::key()['private'], OPENSSL_ALGO_SHA256);

        return $signed.'.'.Base64Url::encode($signature);
    }

    /**
     * A token signed by a key this provider does not publish — an attacker with
     * their own key pair.
     *
     * @param  array<string, mixed>  $claims
     */
    public function idTokenFromAnotherKey(array $claims): string
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        $payload = [
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'subject-1',
            'exp' => time() + 300,
            'iat' => time(),
            ...$claims,
        ];

        $signed = Base64Url::encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => self::KEY_ID]))
            .'.'.Base64Url::encode((string) json_encode($payload));

        openssl_sign($signed, $signature, $other, OPENSSL_ALGO_SHA256);

        return $signed.'.'.Base64Url::encode($signature);
    }

    /**
     * @return array<string, mixed>
     */
    public function jwk(): array
    {
        return self::key()['jwk'];
    }

    /**
     * @return array{private: \OpenSSLAsymmetricKey, jwk: array<string, mixed>}
     */
    private static function key(): array
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($private);

        return self::$key = [
            'private' => $private,
            'jwk' => [
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => self::KEY_ID,
                'n' => Base64Url::encode($details['rsa']['n']),
                'e' => Base64Url::encode($details['rsa']['e']),
            ],
        ];
    }
}
