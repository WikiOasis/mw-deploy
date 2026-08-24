<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DecisionReason;
use App\Enums\DeploymentDecision;
use App\Enums\DeploymentIntent;
use App\Enums\DeploymentStatus;
use App\Enums\RepoAction;
use App\Enums\StepStatus;
use App\Support\DeploymentOptions;
use Database\Factories\DeploymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable([
    'created_by', 'status', 'intent', 'options', 'mediawiki_version_id',
    'rolls_back_deployment_id',
    'pending_decision', 'pending_decision_context', 'pending_decision_requested_at',
    'decision_response', 'decision_by', 'decision_answered_at',
    'abort_requested_at', 'abort_requested_by', 'abort_rollback',
    'failure_reason', 'started_at', 'finished_at',
])]
class Deployment extends Model
{
    /** @use HasFactory<DeploymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => DeploymentStatus::class,
            'intent' => DeploymentIntent::class,
            'options' => 'array',
            'pending_decision' => DecisionReason::class,
            'pending_decision_context' => 'array',
            'decision_response' => DeploymentDecision::class,
            'pending_decision_requested_at' => 'datetime',
            'decision_answered_at' => 'datetime',
            'abort_requested_at' => 'datetime',
            'abort_rollback' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decision_by');
    }

    public function abortRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'abort_requested_by');
    }

    public function mediawikiVersion(): BelongsTo
    {
        return $this->belongsTo(MediaWikiVersion::class, 'mediawiki_version_id');
    }

    public function rollsBack(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rolls_back_deployment_id');
    }

    public function rollbacks(): HasMany
    {
        return $this->hasMany(self::class, 'rolls_back_deployment_id');
    }

    public function repoRefs(): HasMany
    {
        return $this->hasMany(DeploymentRepoRef::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(DeploymentStep::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(RepoStateSnapshot::class);
    }

    public function deploymentPatches(): HasMany
    {
        return $this->hasMany(DeploymentPatch::class);
    }

    public function opts(): DeploymentOptions
    {
        return DeploymentOptions::fromArray($this->options ?? []);
    }

    public function isRollback(): bool
    {
        return $this->rolls_back_deployment_id !== null;
    }

    /**
     * Whether this deployment removes anything. Drives the confirmation copy and
     * the permission check, and is derived from the refs rather than the intent
     * so a mislabelled intent cannot smuggle a removal through.
     */
    public function removesAnything(): bool
    {
        return $this->repoRefs->contains(
            fn (DeploymentRepoRef $ref) => $ref->action === RepoAction::Undeploy
        );
    }

    /**
     * @return Collection<int, DeploymentRepoRef>
     */
    public function refsFor(RepoAction $action): Collection
    {
        return $this->repoRefs->filter(fn (DeploymentRepoRef $ref) => $ref->action === $action)->values();
    }

    public function awaitingDecision(): bool
    {
        return $this->pending_decision !== null && $this->decision_response === null;
    }

    public function durationSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        // Carbon 3 returns a float here.
        return (int) $this->started_at->diffInSeconds($this->finished_at ?? now(), true);
    }

    /**
     * Steps grouped by the host they ran on, ordered as they were queued.
     *
     * @return Collection<string, Collection<int, DeploymentStep>>
     */
    public function stepsByHost(): Collection
    {
        return $this->steps
            ->sortBy([['sequence', 'asc'], ['id', 'asc']])
            ->groupBy('target_hostname');
    }

    /**
     * Hostnames this deployment actually started work on. Used to scope a
     * rollback to servers that were touched — servers the failed deployment never
     * reached are still on the previous ref and were never at risk.
     *
     * @return list<string>
     */
    public function touchedAppservers(): array
    {
        $staging = (string) config('mwdeploy.targets.staging');

        return $this->steps()
            ->where('target_hostname', '!=', $staging)
            ->whereNotIn('status', [StepStatus::Pending->value, StepStatus::Skipped->value])
            ->distinct()
            ->pluck('target_hostname')
            ->values()
            ->all();
    }

    /**
     * Short description for history rows.
     */
    public function summary(): string
    {
        if ($this->isRollback()) {
            return 'Rollback of #'.$this->rolls_back_deployment_id;
        }

        if ($this->intent === DeploymentIntent::VersionCreate) {
            return 'Created '.($this->mediawikiVersion?->version ?? 'a core version');
        }

        if ($this->intent === DeploymentIntent::VersionUndeploy) {
            return 'Undeployed '.($this->mediawikiVersion?->version ?? 'a core version');
        }

        // No line items by design: what shipped was whatever the staging tree
        // held, so naming the tree is the only honest summary.
        if ($this->intent === DeploymentIntent::SyncStaging) {
            return 'Synced '.rtrim((string) config('mwdeploy.paths.staging'), '/').' as it stood';
        }

        return $this->repoRefs->map(fn (DeploymentRepoRef $ref) => $ref->summary())->implode(', ') ?: '—';
    }
}
