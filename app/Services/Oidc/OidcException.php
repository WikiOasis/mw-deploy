<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use RuntimeException;

/**
 * A sign-in attempt that could not be completed.
 *
 * Every message here is written to be shown to the person who was trying to sign
 * in: it says what failed without saying anything a stranger could use, and the
 * detail an administrator needs goes to the log instead.
 */
final class OidcException extends RuntimeException
{
    private function __construct(string $message, public readonly string $logContext = '')
    {
        parent::__construct($message);
    }

    /**
     * A failure whose cause is worth a different line in the log than the one
     * the person signing in is shown.
     */
    public static function because(string $message, string $logContext = ''): self
    {
        return new self($message, $logContext === '' ? $message : $logContext);
    }

    /** Single sign-on was attempted on an install that has not set it up. */
    public static function notConfigured(): self
    {
        return new self('Single sign-on is not configured on this console.');
    }

    /**
     * The IdP answered, but not in a way the flow can continue from. The
     * provider's own error code goes to the log, not to the browser.
     */
    public static function providerRefused(string $detail): self
    {
        return new self(
            'The identity provider refused the sign-in attempt.',
            'provider refused: '.$detail,
        );
    }
}
