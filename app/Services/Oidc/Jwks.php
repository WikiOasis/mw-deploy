<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The IdP's public signing keys, cached, and turned into something openssl will
 * accept.
 *
 * Cached for an hour because a key set is fetched on every sign-in otherwise, and
 * re-fetched immediately when a token arrives signed by a key id the cached set
 * does not contain — which is exactly what a key rotation looks like from here.
 * That re-fetch is the only unauthenticated outbound request sign-in makes, and
 * it happens at most once per sign-in attempt, so a token with a made-up `kid`
 * cannot be used to hammer the IdP.
 */
final class Jwks
{
    private const TTL = 3600;

    /**
     * JWKS URIs already re-fetched during this request, so one sign-in attempt
     * can cause at most one extra fetch.
     *
     * @var array<string, true>
     */
    private array $refreshed = [];

    /**
     * A PEM public key for the given key id.
     *
     * @throws OidcException
     */
    public function publicKey(string $jwksUri, ?string $keyId, string $algorithm): string
    {
        $keys = $this->keys($jwksUri);
        $key = $this->select($keys, $keyId);

        if ($key === null && ! isset($this->refreshed[$jwksUri])) {
            $this->refreshed[$jwksUri] = true;

            Cache::forget($this->cacheKey($jwksUri));

            $key = $this->select($this->keys($jwksUri), $keyId);
        }

        if ($key === null) {
            throw OidcException::because(
                'The identity provider signed the token with a key this console cannot find.',
                'no JWKS key matched kid='.($keyId ?? 'null').' alg='.$algorithm,
            );
        }

        return $this->toPem($key);
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws OidcException
     */
    private function keys(string $jwksUri): array
    {
        $cached = Cache::remember($this->cacheKey($jwksUri), self::TTL, function () use ($jwksUri): array {
            try {
                $response = Http::timeout(10)->acceptJson()->get($jwksUri);
            } catch (ConnectionException $exception) {
                throw OidcException::because(
                    'Could not reach the identity provider to check the token signature.',
                    'JWKS fetch failed: '.$exception->getMessage(),
                );
            }

            if ($response->failed()) {
                throw OidcException::because(
                    'The identity provider would not hand over its signing keys.',
                    'JWKS returned '.$response->status(),
                );
            }

            $keys = $response->json('keys');

            return is_array($keys) ? array_values(array_filter($keys, 'is_array')) : [];
        });

        return is_array($cached) ? $cached : [];
    }

    /**
     * @param  list<array<string, mixed>>  $keys
     * @return array<string, mixed>|null
     */
    private function select(array $keys, ?string $keyId): ?array
    {
        $candidates = array_values(array_filter($keys, static function (array $key): bool {
            // A key with no `use` is usable for signing; one marked for
            // encryption is not, whatever its kid says.
            $usage = $key['use'] ?? 'sig';

            // RSA only: every IdP worth pointing this at signs with RS256, and
            // IdTokenVerifier refuses anything else outright rather than
            // half-supporting EC and getting the curve handling wrong.
            return ($key['kty'] ?? null) === 'RSA' && $usage === 'sig';
        }));

        if ($keyId !== null) {
            foreach ($candidates as $key) {
                if (($key['kid'] ?? null) === $keyId) {
                    return $key;
                }
            }

            /*
             * A kid that matches nothing is not silently ignored, because
             * picking "the other key" when the token names a key we do not have
             * is how signature checking gets quietly defeated.
             */
            return null;
        }

        // No kid at all: only unambiguous when the IdP publishes one signing key.
        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * RSA modulus and exponent to a PEM SubjectPublicKeyInfo, by hand, because
     * this is the only place in the application that needs it and it is a dozen
     * lines of DER rather than a dependency.
     *
     * @param  array<string, mixed>  $key
     *
     * @throws OidcException
     */
    private function toPem(array $key): string
    {
        $modulus = Base64Url::decode((string) ($key['n'] ?? ''));
        $exponent = Base64Url::decode((string) ($key['e'] ?? ''));

        if ($modulus === '' || $exponent === '') {
            throw OidcException::because(
                'The identity provider published a signing key this console cannot read.',
                'JWKS key missing n/e',
            );
        }

        $sequence = $this->der(0x30, $this->integer($modulus).$this->integer($exponent));

        // rsaEncryption OID, then the key sequence as a BIT STRING.
        $algorithm = $this->der(0x30, $this->der(0x06, hex2bin('2a864886f70d010101')).$this->der(0x05, ''));
        $publicKey = $this->der(0x03, "\x00".$sequence);

        $der = $this->der(0x30, $algorithm.$publicKey);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n").'-----END PUBLIC KEY-----';
    }

    /** A DER INTEGER, with the leading zero a positive number needs. */
    private function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes !== '' && (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return $this->der(0x02, $bytes);
    }

    /** A DER tag-length-value, with the long-form length when it is needed. */
    private function der(int $tag, string $value): string
    {
        $length = strlen($value);

        if ($length < 0x80) {
            return chr($tag).chr($length).$value;
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr($tag).chr(0x80 | strlen($bytes)).$bytes.$value;
    }

    /** Keyed by URI, so pointing the console at a different IdP does not read the old keys. */
    private function cacheKey(string $jwksUri): string
    {
        return 'oidc:jwks:'.sha1($jwksUri);
    }
}
