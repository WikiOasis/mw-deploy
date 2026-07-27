<?php

declare(strict_types=1);

namespace App\Services\Discovery;

/**
 * What importing one scanned entry would do to the registry.
 *
 * Every action here is registry-only. Nothing in the import path clones, fetches,
 * checks out or removes anything: the code is already on disk, which is the whole
 * premise — the portal is being told about a farm, not building one.
 */
enum ImportAction: string
{
    /** On disk, nothing in the registry knows about it. */
    case CreateRepository = 'create_repository';

    /** The repository is registered, but not in this core version. */
    case CreateCheckout = 'create_checkout';

    /** The row exists and says undeployed, but the checkout is right there on disk. */
    case AdoptCheckout = 'adopt_checkout';

    /** Registered and present, but sitting on a different ref than it pins. */
    case Repin = 'repin';

    /** Registered git URL differs from the remote the checkout actually has. */
    case UpdateUrl = 'update_url';

    /** The registry says present; the tree does not have it. */
    case MarkUndeployed = 'mark_undeployed';

    /** A versions/<ver> tree with no mediawiki_versions row. */
    case CreateVersion = 'create_version';

    /** Registry and disk agree. Listed so the screen can show coverage, not gaps. */
    case InSync = 'in_sync';

    /** On disk but unregisterable — no git remote, no readable HEAD. */
    case Unimportable = 'unimportable';

    public function label(): string
    {
        return match ($this) {
            self::CreateRepository => 'Register',
            self::CreateCheckout => 'Add checkout',
            self::AdoptCheckout => 'Adopt',
            self::Repin => 'Update pin',
            self::UpdateUrl => 'Update remote',
            self::MarkUndeployed => 'Mark undeployed',
            self::CreateVersion => 'Add version',
            self::InSync => 'In sync',
            self::Unimportable => 'Cannot import',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CreateRepository => 'Create a registry entry and a checkout row for what is on disk, pinned to the ref it is on.',
            self::CreateCheckout => 'The repository is already registered; this adds the checkout row for this core version.',
            self::AdoptCheckout => 'The registry has this checkout marked undeployed, but it is on disk. Mark it deployed.',
            self::Repin => 'The registry pins one ref and the tree is on another. Repin to match the tree.',
            self::UpdateUrl => 'Point the registry at the remote the checkout on disk actually uses.',
            self::MarkUndeployed => 'The registry claims this is deployed but it is not in the tree. Record it as undeployed.',
            self::CreateVersion => 'Register the core version this tree contains.',
            self::InSync => 'Nothing to do.',
            self::Unimportable => 'This directory has no git remote to deploy from, so no registry row can describe it.',
        };
    }

    /**
     * Whether this action is selected by default on the import screen.
     *
     * Everything additive is: the point of the screen is one click to adopt a farm.
     * The two that change existing rows — a repin and a URL rewrite — are not,
     * because an operator may have pinned deliberately and the tree may be the
     * thing that is wrong.
     */
    public function recommended(): bool
    {
        return match ($this) {
            self::CreateRepository, self::CreateCheckout, self::AdoptCheckout, self::CreateVersion => true,
            self::Repin, self::UpdateUrl, self::MarkUndeployed, self::InSync, self::Unimportable => false,
        };
    }

    public function isActionable(): bool
    {
        return $this !== self::InSync && $this !== self::Unimportable;
    }

    /**
     * Actions that only ever add rows, and so are safe to apply unattended — this
     * is what `mwdeploy:import-tree --apply` does without extra flags.
     */
    public function isAdditive(): bool
    {
        return match ($this) {
            self::CreateRepository, self::CreateCheckout, self::AdoptCheckout, self::CreateVersion => true,
            default => false,
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::CreateRepository, self::CreateVersion => 'bg-sky-100 text-sky-800 ring-sky-300',
            self::CreateCheckout, self::AdoptCheckout => 'bg-emerald-100 text-emerald-800 ring-emerald-300',
            self::Repin, self::UpdateUrl => 'bg-amber-100 text-amber-900 ring-amber-300',
            self::MarkUndeployed => 'bg-orange-100 text-orange-900 ring-orange-300',
            self::InSync => 'bg-slate-100 text-slate-500 ring-slate-300',
            self::Unimportable => 'bg-rose-100 text-rose-900 ring-rose-300',
        };
    }
}
