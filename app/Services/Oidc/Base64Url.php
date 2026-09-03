<?php

declare(strict_types=1);

namespace App\Services\Oidc;

/**
 * base64url, which is base64 with two characters swapped and the padding left
 * off. Everything in a JWT is encoded this way.
 */
final class Base64Url
{
    /** Lenient on purpose: a token from an encoder that left the padding on still decodes. */
    public static function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), strict: false);

        return $decoded === false ? '' : $decoded;
    }

    /** Padding stripped, because a JWT segment carries none. */
    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
