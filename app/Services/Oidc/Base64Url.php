<?php

declare(strict_types=1);

namespace App\Services\Oidc;

/**
 * base64url, which is base64 with two characters swapped and the padding left
 * off. Everything in a JWT is encoded this way.
 */
final class Base64Url
{
    public static function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), strict: false);

        return $decoded === false ? '' : $decoded;
    }

    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
