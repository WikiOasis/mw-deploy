<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical step names recorded in deployment_steps.step_name.
 *
 * These double as the shim subcommand names wherever a step maps 1:1 onto a
 * shim call, which keeps the Salt timeout table in config/mwdeploy.php keyed by
 * something meaningful.
 */
enum StepName: string
{
    case GitCheckout = 'git-checkout';
    case GitPull = 'git-pull';
    case GitHead = 'git-head';
    case GitRefs = 'git-refs';
    case RepoRegister = 'repo-register';
    case PatchApply = 'patch-apply';
    case RsyncLocal = 'rsync-local';
    case RsyncRemote = 'rsync-remote';
    case L10nRebuild = 'l10n-rebuild';
    case Canary = 'canary';
    case HaproxyDepool = 'haproxy-depool';
    case HaproxyRepool = 'haproxy-repool';

    public function label(): string
    {
        return match ($this) {
            self::GitCheckout => 'Checkout ref',
            self::GitPull => 'Pull tracked branch',
            self::GitHead => 'Read current HEAD',
            self::GitRefs => 'List branches and commits',
            self::RepoRegister => 'Register repository',
            self::PatchApply => 'Apply patch',
            self::RsyncLocal => 'Rsync staging → production (local)',
            self::RsyncRemote => 'Rsync to appserver',
            self::L10nRebuild => 'Rebuild l10n cache',
            self::Canary => 'Canary check',
            self::HaproxyDepool => 'Depool from HAProxy',
            self::HaproxyRepool => 'Repool into HAProxy',
        };
    }

    public function saltTimeout(): int
    {
        $configured = config('mwdeploy.salt.timeouts.'.$this->value);

        return is_numeric($configured)
            ? (int) $configured
            : (int) config('mwdeploy.salt.default_timeout', 300);
    }
}
