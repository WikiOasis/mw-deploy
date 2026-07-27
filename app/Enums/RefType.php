<?php

declare(strict_types=1);

namespace App\Enums;

enum RefType: string
{
    case Branch = 'branch';
    case Commit = 'commit';

    /**
     * Best guess when no type was supplied. Deliberately lenient: abbreviated
     * SHAs are commonly pasted at 7 characters.
     */
    public static function detect(string $ref): self
    {
        return preg_match('/^[0-9a-f]{7,40}$/i', $ref) === 1
            ? self::Commit
            : self::Branch;
    }

    /**
     * Settle a submitted type against the value actually typed.
     *
     * An unambiguous SHA is a commit whichever radio button was ticked — pasting
     * one into the branch field is a common slip, and recording it as a branch
     * would make the rollback snapshot lie about what was deployed. Below 12
     * characters the submitted type wins, since short hex strings are plausible
     * branch names.
     */
    public static function reconcile(?string $submitted, string $value): self
    {
        if (preg_match('/^[0-9a-f]{12,40}$/i', $value) === 1) {
            return self::Commit;
        }

        return self::tryFrom((string) $submitted) ?? self::detect($value);
    }
}
