<?php

declare(strict_types=1);

namespace App\Services\Salt;

use App\Enums\RepoAction;
use App\Enums\RepositoryType;
use App\Enums\StepName;
use App\Models\DeploymentRepoRef;
use App\Models\DeployTarget;
use App\Models\MediaWikiVersion;
use App\Models\Patch;
use App\Models\RepositoryVersion;
use App\Services\Deployment\SyncPlan;
use InvalidArgumentException;

/**
 * Single source of truth for "what Salt call implements this step".
 *
 * Both the review screen (which only renders the calls) and the runner (which
 * executes them) build their calls here, so the sequence an operator confirms is
 * literally the sequence that runs.
 */
final class ShimCalls
{
    public function stagingTarget(): string
    {
        return (string) config('mwdeploy.targets.staging');
    }

    public function stagingRoot(): string
    {
        return rtrim((string) config('mwdeploy.paths.staging'), '/');
    }

    public function productionRoot(): string
    {
        return rtrim((string) config('mwdeploy.paths.production'), '/');
    }

    /**
     * Read the current HEAD of a checkout before touching it, so the deployment
     * can record its own undo point in repo_state_snapshots.
     */
    public function gitHead(RepositoryVersion $checkout): SaltCall
    {
        return new SaltCall(
            target: $this->stagingTarget(),
            command: ShimCommand::make(StepName::GitHead)
                ->option('path', $checkout->stagingPath()),
            subject: $checkout->displayName(),
        );
    }

    /**
     * Check out an explicit branch or commit rather than always resetting to the
     * tracked branch tip.
     */
    public function gitCheckout(RepositoryVersion $checkout, string $ref): SaltCall
    {
        return new SaltCall(
            target: $this->stagingTarget(),
            command: ShimCommand::make(StepName::GitCheckout)
                ->option('path', $checkout->stagingPath())
                ->option('ref', $ref),
            subject: $checkout->displayName().' → '.$ref,
        );
    }

    /**
     * Kept for the "just give me the tracked branch tip" workflow.
     */
    public function gitPull(RepositoryVersion $checkout): SaltCall
    {
        return new SaltCall(
            target: $this->stagingTarget(),
            command: ShimCommand::make(StepName::GitPull)
                ->option('path', $checkout->stagingPath()),
            subject: $checkout->displayName(),
        );
    }

    /**
     * Clone a checkout that is not on disk — a newly registered repository, a new
     * version's copy of an extension, or one being restored after an undeploy.
     */
    public function repoRegister(RepositoryVersion $checkout): SaltCall
    {
        $repository = $checkout->repository;

        $command = ShimCommand::make(StepName::RepoRegister)
            ->option('url', $repository->git_url)
            ->option('path', $checkout->stagingPath())
            ->option('branch', $checkout->resolvedRefValue() ?? $repository->default_branch);

        // Core *is* the version directory, so its clone needs the versions/<ver>
        // scaffolding rather than a plain top-level clone.
        if ($repository->type === RepositoryType::Core && $checkout->mediawikiVersion !== null) {
            $command->option('kind', 'core-version')
                ->option('version', $checkout->mediawikiVersion->version);
        }

        return new SaltCall(
            target: $this->stagingTarget(),
            command: $command,
            subject: $checkout->displayName(),
        );
    }

    /**
     * Confirm a remote is reachable before a registry row is written for it.
     */
    public function gitRemoteCheck(string $url, ?string $branch = null): SaltCall
    {
        return new SaltCall(
            target: $this->stagingTarget(),
            command: ShimCommand::make(StepName::GitRemoteCheck)
                ->option('url', $url)
                ->optionalOption('branch', $branch),
            subject: $url,
        );
    }

    /**
     * Create the empty versions/<ver> tree for a new core version.
     */
    public function versionScaffold(MediaWikiVersion $version): SaltCall
    {
        return new SaltCall(
            target: $this->stagingTarget(),
            command: ShimCommand::make(StepName::VersionScaffold)
                ->option('path', $version->stagingPath())
                ->option('version', $version->version),
            subject: 'versions/'.$version->version,
        );
    }

    /**
     * Remove a checkout from one host.
     *
     * `--root` is what makes this safe: the shim refuses any path that is not
     * strictly inside it, refuses the root itself, and refuses a bare
     * versions/<ver> unless `--allow-version-root` says so out loud.
     *
     * Removal is done per host rather than by deleting on staging and letting
     * rsync --delete propagate: those semantics are subtle under a path-restricted
     * include set, and they change entirely under NFS.
     */
    public function repoRemove(
        string $hostname,
        string $absolutePath,
        string $root,
        bool $isVersionRoot = false,
        bool $check = false,
        ?string $subject = null,
    ): SaltCall {
        return new SaltCall(
            target: $hostname,
            command: ShimCommand::make(StepName::RepoRemove)
                ->option('path', $absolutePath)
                ->option('root', $root)
                ->flag('allow-version-root', $isVersionRoot)
                ->flag('check', $check),
            subject: $subject ?? $absolutePath,
        );
    }

    /**
     * Remove a checkout from the staging tree.
     */
    public function removeFromStaging(RepositoryVersion $checkout, bool $check = false): SaltCall
    {
        return $this->repoRemove(
            hostname: $this->stagingTarget(),
            absolutePath: $checkout->stagingPath(),
            root: $this->stagingRoot(),
            check: $check,
            subject: $checkout->displayName().' (staging tree)',
        );
    }

    /**
     * Remove a checkout from a host's production tree. Used on the staging host
     * (which keeps its own production copy) and on every appserver.
     */
    public function removeFromProduction(string $hostname, RepositoryVersion $checkout): SaltCall
    {
        return $this->repoRemove(
            hostname: $hostname,
            absolutePath: $checkout->productionPath(),
            root: $this->productionRoot(),
            subject: $checkout->displayName(),
        );
    }

    /**
     * Remove an entire core version's subtree from a host. Needs the explicit
     * version-root flag, which is the point.
     */
    public function removeVersion(string $hostname, MediaWikiVersion $version, bool $fromStaging = false): SaltCall
    {
        $root = $fromStaging ? $this->stagingRoot() : $this->productionRoot();

        return $this->repoRemove(
            hostname: $hostname,
            absolutePath: $root.'/'.$version->relativePath(),
            root: $root,
            isVersionRoot: true,
            subject: 'versions/'.$version->version.($fromStaging ? ' (staging tree)' : ''),
        );
    }

    /**
     * Which core versions the farm's wikis currently point at, so undeploying a
     * live version can be refused rather than checklisted.
     */
    public function wikiVersions(): SaltCall
    {
        return new SaltCall(
            target: $this->stagingTarget(),
            command: ShimCommand::make(StepName::WikiVersions)
                ->option('file', (string) config('mwdeploy.paths.wiki_versions')),
            subject: basename((string) config('mwdeploy.paths.wiki_versions')),
        );
    }

    public function patchApply(Patch $patch, bool $check = false): SaltCall
    {
        return new SaltCall(
            target: $this->stagingTarget(),
            command: ShimCommand::make(StepName::PatchApply)
                ->option('patch', $patch->shimPatchPath())
                ->option('target-dir', $patch->stagingTargetPath())
                ->option('format', $patch->format)
                ->flag('check', $check),
            subject: $patch->name.($check ? ' (dry run)' : ''),
        );
    }

    /**
     * Staging → production on the staging host itself, which is where the
     * original script's rsync_local ran.
     */
    public function rsyncLocal(SyncPlan $plan, bool $provision = false): SaltCall
    {
        $command = ShimCommand::make(StepName::RsyncLocal)
            ->option('src', $this->stagingRoot().'/')
            ->option('dst', $this->productionRoot().'/')
            ->flag('provision', $provision)
            ->repeatedOption('path', $plan->shimPaths());

        return new SaltCall(
            target: $this->stagingTarget(),
            command: $command,
            subject: $plan->describe(),
        );
    }

    /**
     * Runs *on the appserver*, pulling from the staging host's rsync source.
     */
    public function rsyncRemote(DeployTarget $target, SyncPlan $plan, bool $provision = false): SaltCall
    {
        $command = ShimCommand::make(StepName::RsyncRemote)
            ->option('src', (string) config('mwdeploy.transport.rsync_source'))
            ->option('dst', $this->productionRoot().'/')
            ->flag('provision', $provision)
            ->repeatedOption('path', $plan->shimPaths());

        return new SaltCall(
            target: $target->hostname,
            command: $command,
            subject: $plan->describe(),
        );
    }

    public function l10nRebuild(string $hostname, ?string $wiki = null): SaltCall
    {
        $wiki ??= (string) config('mwdeploy.rollout.l10n_wiki');

        return new SaltCall(
            target: $hostname,
            command: ShimCommand::make(StepName::L10nRebuild)->option('wiki', $wiki),
            subject: $wiki,
        );
    }

    /**
     * One canary result, no interactive prompt: there is no TTY under Salt, and
     * the retry-then-ask logic lives in the job.
     */
    public function canary(string $hostname, ?string $vhost = null, ?int $retries = null): SaltCall
    {
        $vhost ??= (string) config('mwdeploy.rollout.canary_vhost');

        return new SaltCall(
            target: $hostname,
            command: ShimCommand::make(StepName::Canary)
                ->option('vhost', $vhost)
                ->option('retries', $retries ?? (int) config('mwdeploy.rollout.canary_retries')),
            subject: $vhost,
        );
    }

    /**
     * One proxy per call. The job loops over the proxy inventory itself rather
     * than letting the shim fan out, so a single proxy failing is attributable.
     */
    public function haproxy(StepName $step, DeployTarget $proxy, DeployTarget $server): SaltCall
    {
        $action = match ($step) {
            StepName::HaproxyDepool => 'depool',
            StepName::HaproxyRepool => 'repool',
            default => throw new InvalidArgumentException("[{$step->value}] is not an HAProxy step."),
        };

        $backend = $proxy->haproxy_backend ?: (string) config('mwdeploy.rollout.haproxy_backend');

        return new SaltCall(
            target: $proxy->hostname,
            command: ShimCommand::haproxy($step, $action)
                ->option('proxy', $proxy->hostname)
                ->option('backend', $backend)
                ->option('server', $server->haproxyServerName()),
            subject: $server->haproxyServerName().' @ '.$proxy->hostname,
        );
    }

    /**
     * What the rsync steps should cover, given this deployment's line items.
     *
     * Only *deployed* paths are synced. A removal is carried out by an explicit
     * repo-remove per host, so it contributes nothing here — and a deployment
     * that only removes things syncs nothing at all rather than walking the whole
     * tree.
     *
     * @param  iterable<DeploymentRepoRef>  $refs
     */
    public function syncPlanFor(iterable $refs): SyncPlan
    {
        $paths = [];

        foreach ($refs as $ref) {
            if ($ref->action === RepoAction::Undeploy) {
                continue;
            }

            $checkout = $ref->repositoryVersion;

            if ($checkout === null) {
                continue;
            }

            // A core version bump touches too much to express as a path list.
            if ($checkout->repository?->type === RepositoryType::Core) {
                return SyncPlan::fullTree();
            }

            $paths[] = trim($checkout->path, '/');
        }

        sort($paths);

        return SyncPlan::restrictedTo($paths);
    }
}
