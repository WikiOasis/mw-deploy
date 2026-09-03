<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use App\Models\OidcSettings;

/**
 * Checks an ID token before a single claim in it is believed.
 *
 * The token arrives over TLS straight from the token endpoint, which is why some
 * clients skip this entirely. It is checked anyway: this console can push code to
 * every production appserver, and the whole of its authentication now rests on
 * these claims being the IdP's and not somebody else's. Signature, issuer,
 * audience, expiry and the nonce we sent — all of them, or no sign-in.
 *
 * RSA signatures only. An `alg` of `none` — the original JWT footgun — and the
 * symmetric algorithms are refused outright rather than handled, because there
 * is no IdP worth supporting that cannot sign with RS256.
 */
final class IdTokenVerifier
{
    /** Tolerance for clock skew between this host and the IdP. */
    private const LEEWAY = 60;

    /** @var array<string, int> openssl algorithm per JWS `alg` */
    private const ALGORITHMS = [
        'RS256' => OPENSSL_ALGO_SHA256,
        'RS384' => OPENSSL_ALGO_SHA384,
        'RS512' => OPENSSL_ALGO_SHA512,
    ];

    public function __construct(private readonly Jwks $jwks) {}

    /**
     * The token's claims, once every check has passed.
     *
     * @return array<string, mixed>
     *
     * @throws OidcException
     */
    public function verify(string $token, OidcSettings $settings, string $nonce): array
    {
        [$header, $claims, $signature, $signedPayload] = $this->split($token);

        $algorithm = is_string($header['alg'] ?? null) ? $header['alg'] : '';

        if (! array_key_exists($algorithm, self::ALGORITHMS)) {
            throw OidcException::because(
                'The identity provider signed the token with an algorithm this console will not accept.',
                'unsupported alg: '.($algorithm === '' ? 'missing' : $algorithm),
            );
        }

        if (! filled($settings->jwks_uri)) {
            throw OidcException::because(
                'Single sign-on is missing the identity provider\'s key set URL, so tokens cannot be checked.',
                'jwks_uri not configured',
            );
        }

        $key = $this->jwks->publicKey((string) $settings->jwks_uri, $this->keyId($header), $algorithm);

        if (openssl_verify($signedPayload, $signature, $key, self::ALGORITHMS[$algorithm]) !== 1) {
            throw OidcException::because(
                'The sign-in token failed its signature check.',
                'openssl_verify rejected the id_token signature',
            );
        }

        $this->assertClaims($claims, $settings, $nonce);

        return $claims;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string}
     *
     * @throws OidcException
     */
    private function split(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw OidcException::because(
                'The identity provider returned a sign-in token this console could not read.',
                'id_token is not a three-part JWS',
            );
        }

        [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;

        $header = json_decode(Base64Url::decode($encodedHeader), true);
        $claims = json_decode(Base64Url::decode($encodedClaims), true);

        if (! is_array($header) || ! is_array($claims)) {
            throw OidcException::because(
                'The identity provider returned a sign-in token this console could not read.',
                'id_token header or payload is not JSON',
            );
        }

        return [$header, $claims, Base64Url::decode($encodedSignature), $encodedHeader.'.'.$encodedClaims];
    }

    /**
     * @param  array<string, mixed>  $header
     */
    private function keyId(array $header): ?string
    {
        return is_string($header['kid'] ?? null) ? $header['kid'] : null;
    }

    /**
     * @param  array<string, mixed>  $claims
     *
     * @throws OidcException
     */
    private function assertClaims(array $claims, OidcSettings $settings, string $nonce): void
    {
        // Exact string comparison, per the spec: an issuer that merely looks
        // similar is a different issuer.
        if (($claims['iss'] ?? null) !== $settings->issuer) {
            throw OidcException::because(
                'The sign-in token came from a different issuer than the one configured.',
                'iss mismatch',
            );
        }

        if (! in_array((string) $settings->client_id, $this->audiences($claims), true)) {
            throw OidcException::because(
                'The sign-in token was not issued for this console.',
                'aud does not contain the configured client_id',
            );
        }

        /*
         * An ID token with several audiences must name which client asked for
         * it, and that has to be us — otherwise a token minted for another
         * client of the same IdP would be accepted here.
         */
        if (count($this->audiences($claims)) > 1 && ($claims['azp'] ?? null) !== $settings->client_id) {
            throw OidcException::because(
                'The sign-in token was issued for another application.',
                'multi-audience token with azp != client_id',
            );
        }

        $expiry = $claims['exp'] ?? null;

        if (! is_numeric($expiry) || (int) $expiry + self::LEEWAY < time()) {
            throw OidcException::because(
                'The sign-in token had already expired. Try signing in again.',
                'id_token exp is missing or past',
            );
        }

        /*
         * `nbf` is optional in an ID token, but when a provider sends one it
         * means the token is not valid yet — so accepting it early would accept
         * a token the provider itself does not consider live.
         */
        $notBefore = $claims['nbf'] ?? null;

        if ($notBefore !== null && (! is_numeric($notBefore) || (int) $notBefore - self::LEEWAY > time())) {
            throw OidcException::because(
                'The sign-in token is not valid yet. Try signing in again.',
                'id_token nbf is in the future or unreadable',
            );
        }

        $issuedAt = $claims['iat'] ?? null;

        if (! is_numeric($issuedAt) || (int) $issuedAt - self::LEEWAY > time()) {
            throw OidcException::because(
                'The sign-in token is dated in the future, so this host and the identity provider disagree about the time.',
                'id_token iat is missing or in the future',
            );
        }

        /*
         * The nonce ties this token to the authorisation request this browser
         * started. Without it, a token captured from another session could be
         * replayed at the callback.
         */
        if (! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, (string) $claims['nonce'])) {
            throw OidcException::because(
                'The sign-in token did not match the request that started it. Try signing in again.',
                'nonce mismatch',
            );
        }

        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw OidcException::because(
                'The identity provider did not say who signed in.',
                'id_token has no sub',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return list<string>
     */
    private function audiences(array $claims): array
    {
        $audience = $claims['aud'] ?? [];

        return array_values(array_map(
            static fn ($entry): string => (string) $entry,
            is_array($audience) ? $audience : [$audience],
        ));
    }
}
