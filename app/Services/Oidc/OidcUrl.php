<?php

declare(strict_types=1);

namespace App\Services\Oidc;

/**
 * What this console is willing to point single sign-on at.
 *
 * Two rules, both about the fact that these URLs are typed into a form by an
 * administrator and then used by the *server*:
 *
 *  * **HTTPS.** The token endpoint carries the client secret, and the key set is
 *    what decides whether an ID token is genuine. Neither may travel in
 *    cleartext, and Guzzle will follow an HTTPS→HTTP redirect by default, so the
 *    scheme has to be pinned on the requests as well as validated on the form —
 *    see `redirectOptions()`. Loopback is the one exception, because a developer
 *    running an IdP on localhost has no certificate for it and no network for a
 *    packet to be read on.
 *
 *  * **Not the link-local range.** An authenticated administrator can already
 *    ask this host to fetch a URL of their choosing, and an internal IdP on an
 *    RFC1918 address is the *normal* case for this tool — so a host allow-list
 *    would break the feature rather than secure it. 169.254.0.0/16 is different:
 *    nothing legitimate serves OpenID Connect from it, and it is where a cloud
 *    instance keeps its credentials.
 */
final class OidcUrl
{
    /** Hosts where cleartext is acceptable, because the traffic never leaves the box. */
    private const LOOPBACK = ['localhost', '127.0.0.1', '::1', '[::1]'];

    /**
     * Whether this URL may be requested at all: HTTPS (or loopback), and not
     * pointed at the link-local range.
     */
    public static function isAllowed(?string $url): bool
    {
        return self::isSecure($url) && ! self::isLinkLocal($url);
    }

    /**
     * HTTPS, or plain HTTP on loopback.
     */
    public static function isSecure(?string $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && in_array($host, self::LOOPBACK, true);
    }

    /**
     * 169.254.0.0/16 and its IPv6 equivalent — the cloud metadata range.
     *
     * Only a literal address is rejected here. A hostname that resolves into the
     * range is not chased, because the resolution at check time is not
     * necessarily the resolution at request time, and pretending otherwise would
     * be security theatre.
     */
    public static function isLinkLocal(?string $url): bool
    {
        $host = trim((string) parse_url((string) $url, PHP_URL_HOST), '[]');

        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return str_starts_with($host, '169.254.') || str_starts_with(strtolower($host), 'fe80:');
    }

    /**
     * HTTP client options that hold a redirect to the same policy as the URL
     * that was typed in.
     *
     * Two halves, and both are needed:
     *
     *  * `protocols` — Guzzle's default list is `['http', 'https']`, so without
     *    this an IdP, or anything able to answer for it, could bounce a request
     *    carrying the client secret onto plain HTTP.
     *  * `on_redirect` — the protocol list alone only checks the *scheme*. An
     *    allowed HTTPS URL could still redirect to `https://169.254.169.254/`,
     *    which passes the scheme check and would then be fetched by this host.
     *    So every redirect target is put through `isAllowed()` as well, and a
     *    target that fails it aborts the request instead of being followed.
     *
     * @return array<string, mixed>
     */
    public static function redirectOptions(): array
    {
        return [
            'allow_redirects' => [
                'max' => 3,
                'protocols' => ['https'],
                'strict' => true,
                'referer' => false,
                /**
                 * @param  mixed  $request  the request that was redirected
                 * @param  mixed  $response  the redirect response
                 * @param  mixed  $uri  the target, as a PSR-7 UriInterface
                 */
                'on_redirect' => static function ($request, $response, $uri): void {
                    if (! self::isAllowed((string) $uri)) {
                        throw OidcException::because(
                            'The provider redirected the request somewhere this console will not follow.',
                            'refused redirect target: '.(string) $uri,
                        );
                    }
                },
            ],
        ];
    }

    /**
     * The message shown when a URL is refused, naming which rule it broke.
     */
    public static function refusal(string $field): string
    {
        return 'The '.$field.' must be an https:// URL, and cannot point at the link-local address range.';
    }
}
