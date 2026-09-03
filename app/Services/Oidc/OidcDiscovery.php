<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Reads an IdP's /.well-known/openid-configuration.
 *
 * Called from the settings screen, not from sign-in: the endpoints it returns are
 * saved, so an IdP whose discovery document is unavailable for ten minutes does
 * not make the console unreachable for ten minutes. It is also how an
 * administrator finds out they have pasted the wrong URL, while they are looking
 * at the form, rather than the next time somebody tries to sign in.
 */
final class OidcDiscovery
{
    /**
     * @return array{issuer: string, authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: string|null, jwks_uri: string|null, end_session_endpoint: string|null, scopes_supported: list<string>}
     *
     * @throws OidcException
     */
    public function fetch(string $issuerOrUrl): array
    {
        $url = $this->documentUrl($issuerOrUrl);

        try {
            $response = Http::timeout(10)->acceptJson()->get($url);
        } catch (ConnectionException $exception) {
            throw OidcException::because(
                'Could not reach '.$url.'.',
                'discovery connection failed: '.$exception->getMessage(),
            );
        }

        if ($response->failed()) {
            throw OidcException::because(
                $url.' answered '.$response->status().'.',
                'discovery returned '.$response->status(),
            );
        }

        $document = $response->json();

        if (! is_array($document)) {
            throw OidcException::because($url.' did not return a JSON document.');
        }

        foreach (['issuer', 'authorization_endpoint', 'token_endpoint'] as $required) {
            if (! is_string($document[$required] ?? null) || $document[$required] === '') {
                throw OidcException::because(
                    'The discovery document at '.$url.' has no '.$required.', so it cannot be used.',
                );
            }
        }

        return [
            'issuer' => $document['issuer'],
            'authorization_endpoint' => $document['authorization_endpoint'],
            'token_endpoint' => $document['token_endpoint'],
            'userinfo_endpoint' => $this->optional($document, 'userinfo_endpoint'),
            'jwks_uri' => $this->optional($document, 'jwks_uri'),
            'end_session_endpoint' => $this->optional($document, 'end_session_endpoint'),
            /*
             * Advertised scopes, so the settings screen can point out that the
             * groups scope this install is asking for is not one the IdP offers
             * — which is the single most common reason for an SSO account
             * arriving with no roles.
             */
            'scopes_supported' => array_values(array_filter(
                array_map(
                    static fn ($scope): string => is_string($scope) ? $scope : '',
                    is_array($document['scopes_supported'] ?? null) ? $document['scopes_supported'] : [],
                ),
                static fn (string $scope): bool => $scope !== '',
            )),
        ];
    }

    /**
     * Accepts either the issuer or the full document URL, because both are what
     * people have to hand: IdPs print the issuer, and their documentation links
     * the well-known URL.
     */
    public function documentUrl(string $issuerOrUrl): string
    {
        $url = trim($issuerOrUrl);

        if (Str::contains($url, '/.well-known/')) {
            return $url;
        }

        return rtrim($url, '/').'/.well-known/openid-configuration';
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function optional(array $document, string $key): ?string
    {
        $value = $document[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
