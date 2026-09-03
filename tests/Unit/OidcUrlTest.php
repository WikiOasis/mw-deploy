<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Oidc\OidcException;
use App\Services\Oidc\OidcUrl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the console is willing to point single sign-on at.
 *
 * A unit test rather than a feature one because the interesting half is the
 * redirect guard, and the HTTP fake used by the feature tests short-circuits
 * Guzzle's handler stack — a faked request never redirects, so `on_redirect`
 * would never be reached there. The callback is exercised directly instead.
 */
final class OidcUrlTest extends TestCase
{
    #[Test]
    public function https_is_allowed_and_cleartext_is_not(): void
    {
        $this->assertTrue(OidcUrl::isAllowed('https://sso.example.test/application/o/console'));

        $this->assertFalse(OidcUrl::isAllowed('http://sso.example.test/'));
        $this->assertFalse(OidcUrl::isAllowed('ftp://sso.example.test/'));
        $this->assertFalse(OidcUrl::isAllowed(''));
        $this->assertFalse(OidcUrl::isAllowed(null));
    }

    #[Test]
    public function loopback_may_use_plain_http(): void
    {
        // A developer running an IdP on localhost has no certificate for it, and
        // no network for a packet to be read on.
        $this->assertTrue(OidcUrl::isAllowed('http://localhost:9000/token'));
        $this->assertTrue(OidcUrl::isAllowed('http://127.0.0.1:9000/token'));
        $this->assertTrue(OidcUrl::isAllowed('http://[::1]:9000/token'));
    }

    #[Test]
    public function the_link_local_range_is_refused_even_over_https(): void
    {
        // Nothing legitimate serves OpenID Connect from it, and it is where a
        // cloud instance keeps its credentials.
        $this->assertTrue(OidcUrl::isLinkLocal('https://169.254.169.254/latest/meta-data/'));
        $this->assertTrue(OidcUrl::isLinkLocal('https://[fe80::1]/'));

        $this->assertFalse(OidcUrl::isAllowed('https://169.254.169.254/latest/meta-data/'));
        $this->assertFalse(OidcUrl::isAllowed('https://[fe80::1]/'));
    }

    #[Test]
    public function a_hostname_that_merely_looks_link_local_is_not_refused(): void
    {
        // Only literal addresses are judged: a name is not resolved at check
        // time, because the answer then need not be the answer at request time.
        $this->assertFalse(OidcUrl::isLinkLocal('https://169.254.example.test/'));
        $this->assertTrue(OidcUrl::isAllowed('https://169.254.example.test/'));
    }

    #[Test]
    public function a_private_address_is_allowed_because_an_internal_idp_is_the_normal_case(): void
    {
        $this->assertTrue(OidcUrl::isAllowed('https://10.0.5.20/application/o/console'));
        $this->assertTrue(OidcUrl::isAllowed('https://192.168.1.10/jwks'));
    }

    #[Test]
    public function redirects_are_restricted_to_https(): void
    {
        $options = OidcUrl::redirectOptions()['allow_redirects'];

        $this->assertSame(['https'], $options['protocols']);
        $this->assertTrue($options['strict']);
        $this->assertSame(3, $options['max']);
    }

    #[Test]
    public function a_redirect_target_is_held_to_the_same_policy_as_the_url_that_was_typed_in(): void
    {
        /*
         * The protocol list alone only checks the scheme, so an allowed HTTPS
         * URL could otherwise redirect to the metadata address and have this
         * host fetch it.
         */
        $onRedirect = OidcUrl::redirectOptions()['allow_redirects']['on_redirect'];

        $this->expectException(OidcException::class);

        $onRedirect(null, null, 'https://169.254.169.254/latest/meta-data/');
    }

    #[Test]
    public function a_legitimate_redirect_is_followed(): void
    {
        $onRedirect = OidcUrl::redirectOptions()['allow_redirects']['on_redirect'];

        $onRedirect(null, null, 'https://sso.example.test/application/o/console/');

        // No exception: an IdP moving its well-known document is ordinary.
        $this->expectNotToPerformAssertions();
    }
}
