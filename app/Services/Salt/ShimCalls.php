<?php

declare(strict_types=1);

namespace App\Services\Salt;

use App\Enums\RepositoryType;
use App\Enums\StepName;
use App\Models\DeploymentRepoRef;
use App\Models\DeployTarget;
use App\Models\Patch;
use App\Models\Repository;
use App\Support\DeploymentOptions;
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

    /**
     * Read the current HEAD of a repo before touching it, so the deployment can
     * record its own undo point in repo_state_snapshots.
     */
    public function gitHead(Repository $repository): SaltCall
    {
        return new SaltCall(
            target: $this->stagingTarget(),
            command: ShimCommand::make(StepName::GitHead)
                ->option('path', $repository->stagingPath()),
            subject: $repository->displayName(),
        );
    }

    /**
     * The new capability: check out an explicit branch or commit rather than
     * always resetting to the tracked branch tip.
     */
    public function gitCheckout(DeploymentRepoRef $ref): SaltCall
    {
        $repository = $ref->repository;

        return new SaltCall(
            target: $this->stagingTarget(),
            command: ShimCommand::make(StepName::GitCheckout)
                ->option('path', $repository->stagingPath())
                ->option('ref', $ref->ref_value),
            subject: $repository->displayName().' → '.$ref->shortRef(),
        );
    }

    /**
     * Kept for the "just give me the tracked branch tip" workflow.
     */
    public function gitPull(Repository $repository): SaltCall
    {
        return new SaltCall(
            target: $this->stagingTarget(),
            command: ShimCommand::make(StepName::GitPull)
                ->option('path', $repository->stagingPath()),
            subject: $repository->displayName(),
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
     *
     * @param  list<string>  $paths  restrict the sync to these relative paths
     */
    public function rsyncLocal(array $paths = [], bool $provision = false): SaltCall
    {
        $staging = rtrim((string) config('mwdeploy.paths.staging'), '/');
        $production = rtrim((string) config('mwdeploy.paths.production'), '/');

        $command = ShimCommand::make(StepName::RsyncLocal)
            ->option('src', $staging.'/')
            ->option('dst', $production.'/')
            ->flag('provision', $provision)
            ->repeatedOption('path', $paths);

        return new SaltCall(
            target: $this->stagingTarget(),
            command: $command,
            subject: $paths === [] ? 'full tree' : implode(', ', $paths),
        );
    }

    /**
     * Runs *on the appserver*, pulling from the staging host's rsync source.
     * See config('mwdeploy.transport.rsync_source') and open question 1.
     *
     * @param  list<string>  $paths
     */
    public function rsyncRemote(DeployTarget $target, array $paths = [], bool $provision = false): SaltCall
    {
        $source = (string) config('mwdeploy.transport.rsync_source');
        $production = rtrim((string) config('mwdeploy.paths.production'), '/');

        $command = ShimCommand::make(StepName::RsyncRemote)
            ->option('src', $source)
            ->option('dst', $production.'/')
            ->flag('provision', $provision)
            ->repeatedOption('path', $paths);

        return new SaltCall(
            target: $target->hostname,
            command: $command,
            subject: $paths === [] ? 'full tree' : implode(', ', $paths),
        );
    }

    public function l10nRebuild(string $hostname, ?string $wiki = null): SaltCall
    {
        return new SaltCall(
            target: $hostname,
            command: ShimCommand::make(StepName::L10nRebuild)
                ->option('wiki', $wiki ?? (string) config('mwdeploy.rollout.l10n_wiki')),
            subject: $wiki ?? (string) config('mwdeploy.rollout.l10n_wiki'),
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
     * Initial clone of a newly-registered repository into the staging tree.
     */
    public function repoRegister(Repository $repository): SaltCall
    {
        $command = ShimCommand::make(StepName::RepoRegister)
            ->option('url', $repository->git_url)
            ->option('path', $repository->stagingPath())
            ->option('branch', $repository->default_branch);

        // A new core version needs the versions/<ver>/ scaffolding, not a plain
        // top-level clone.
        if ($repository->type === RepositoryType::Core && $repository->core_version !== null) {
            $command->option('kind', 'core-version')
                ->option('version', $repository->core_version);
        }

        return new SaltCall(
            target: $this->stagingTarget(),
            command: $command,
            subject: $repository->displayName(),
        );
    }

    /**
     * Relative paths to restrict rsync to, derived from the repos in the deploy.
     * Syncing only what changed is what keeps a one-extension deploy from
     * walking the whole 700-wiki tree.
     *
     * @param  iterable<DeploymentRepoRef>  $refs
     * @return list<string>
     */
    public function relativePathsFor(iterable $refs): array
    {
        $paths = [];

        foreach ($refs as $ref) {
            $paths[] = trim($ref->repository->path, '/');
        }

        sort($paths);

        return array_values(array_unique($paths));
    }

    /**
     * Whether this deployment should sync the whole tree rather than a path
     * subset. Core version bumps and provisioning runs touch too much to be
     * expressed as a path list.
     *
     * @param  iterable<DeploymentRepoRef>  $refs
     */
    public function requiresFullTreeSync(iterable $refs, DeploymentOptions $options): bool
    {
        foreach ($refs as $ref) {
            if ($ref->repository->type === RepositoryType::Core) {
                return true;
            }
        }

        return false;
    }
}
