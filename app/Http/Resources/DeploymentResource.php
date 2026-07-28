<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\DeploymentDecision;
use App\Models\Deployment;
use App\Models\DeploymentPatch;
use App\Models\DeploymentRepoRef;
use App\Models\DeploymentStep;
use App\Models\RepoStateSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A deployment, in list shape by default and with its steps, snapshots and
 * patches when asked.
 *
 * The detail flag exists because the history screen renders 25 of these and the
 * step logs are the largest thing in the database.
 *
 * @mixin Deployment
 */
final class DeploymentResource extends JsonResource
{
    private bool $detailed = false;

    public function detailed(bool $detailed = true): self
    {
        $this->detailed = $detailed;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $options = $this->opts();

        $payload = [
            'id' => $this->getKey(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_classes' => $this->status->badgeClasses(),
            'terminal' => $this->status->isTerminal(),
            'intent' => $this->intent->value,
            'intent_label' => $this->intent->label(),
            'intent_classes' => $this->intent->badgeClasses(),
            'summary' => $this->summary(),
            'removes_anything' => $this->relationLoaded('repoRefs') ? $this->removesAnything() : null,
            'is_rollback' => $this->isRollback(),
            'rolls_back_id' => $this->rolls_back_deployment_id,
            'rollback_ids' => $this->whenLoaded(
                'rollbacks',
                fn (): array => $this->rollbacks->modelKeys(),
            ),
            'version' => $this->whenLoaded('mediawikiVersion', fn () => $this->mediawikiVersion?->version),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration' => $this->durationSeconds(),
            'failure_reason' => $this->failure_reason,

            'awaiting_decision' => $this->awaitingDecision(),
            'pending_decision' => $this->pending_decision?->value,
            'pending_decision_label' => $this->pending_decision?->label(),
            'pending_decision_context' => $this->pending_decision_context,
            'decision_response' => $this->decision_response?->value,
            'decision_response_label' => $this->decision_response?->label(),
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->name),
            'decided_at' => $this->decision_answered_at?->toIso8601String(),

            'options' => $options->toArray(),
            'option_flags' => $options->summaryFlags(),

            'refs' => $this->whenLoaded('repoRefs', fn (): array => $this->repoRefs
                ->map(fn (DeploymentRepoRef $ref): array => [
                    'id' => $ref->getKey(),
                    'checkout_id' => $ref->repository_version_id,
                    'repository_id' => $ref->logicalRepository()?->getKey(),
                    'name' => $ref->repositoryVersion?->repository?->name,
                    'type' => $ref->logicalRepository()?->type->value,
                    'version' => $ref->repositoryVersion?->mediawikiVersion?->version,
                    'path' => $ref->repositoryVersion?->path,
                    'action' => $ref->action->value,
                    'action_label' => $ref->action->label(),
                    'ref_type' => $ref->ref_type?->value,
                    'ref_value' => $ref->ref_value,
                    'short_ref' => $ref->shortRef(),
                    'summary' => $ref->summary(),
                ])->values()->all()),

            'can' => [
                'rollback' => $user?->can('rollback', $this->resource) ?? false,
                'decide' => $user?->can('decide', $this->resource) ?? false,
                'cancel' => $user?->can('cancel', $this->resource) ?? false,
                'abort' => $user?->can('abort', $this->resource) ?? false,
            ],

            'abort_requested_at' => $this->abort_requested_at?->toIso8601String(),
            'abort_requested_by' => $this->whenLoaded('abortRequestedBy', fn () => $this->abortRequestedBy?->name),
        ];

        if (! $this->detailed) {
            return $payload;
        }

        return [
            ...$payload,
            'staging_host' => (string) config('mwdeploy.targets.staging'),
            'decisions' => array_map(
                static fn (DeploymentDecision $decision): array => [
                    'value' => $decision->value,
                    'label' => $decision->label(),
                    'description' => $decision->description(),
                ],
                DeploymentDecision::cases(),
            ),
            'steps_by_host' => $this->whenLoaded('steps', fn (): array => $this->stepsByHost()
                ->map(fn (Collection $steps): array => StepResource::collection($steps)->resolve())
                ->all()),
            'snapshots' => $this->whenLoaded('snapshots', fn (): array => $this->snapshots
                ->map(fn (RepoStateSnapshot $snapshot): array => [
                    'checkout' => $snapshot->repositoryVersion?->displayName(),
                    'path' => $snapshot->repositoryVersion?->path,
                    'summary' => $snapshot->summary(),
                    'previous_present' => $snapshot->previous_present,
                    'previous_ref' => $snapshot->previous_ref_value,
                    'new_present' => $snapshot->new_present,
                    'new_ref' => $snapshot->new_ref_value,
                    'rollbackable' => $snapshot->isRollbackable(),
                ])->values()->all()),
            'patches' => $this->whenLoaded('deploymentPatches', fn (): array => $this->deploymentPatches
                ->map(fn (DeploymentPatch $applied): array => [
                    'id' => $applied->patch_id,
                    'name' => $applied->patch?->name,
                    'applied' => $applied->applied,
                    'applied_to_ref' => $applied->applied_to_ref,
                ])->values()->all()),
        ];
    }

    /**
     * The lean payload the live dashboard polls for, and the shape
     * DeploymentProgressed broadcasts.
     *
     * @return array<string, mixed>
     */
    public static function state(Deployment $deployment): array
    {
        $deployment->loadMissing('steps');

        return [
            'id' => $deployment->getKey(),
            'status' => $deployment->status->value,
            'status_label' => $deployment->status->label(),
            'status_classes' => $deployment->status->badgeClasses(),
            'terminal' => $deployment->status->isTerminal(),
            'awaiting_decision' => $deployment->awaitingDecision(),
            'pending_decision' => $deployment->pending_decision?->value,
            'pending_decision_label' => $deployment->pending_decision?->label(),
            'pending_decision_context' => $deployment->pending_decision_context,
            'failure_reason' => $deployment->failure_reason,
            'duration' => $deployment->durationSeconds(),
            'steps' => $deployment->steps
                ->sortBy([['sequence', 'asc'], ['id', 'asc']])
                ->map(fn (DeploymentStep $step): array => [
                    'id' => $step->getKey(),
                    'host' => $step->target_hostname,
                    'step_name' => $step->step_name->value,
                    'label' => $step->label(),
                    'status' => $step->status->value,
                    'status_classes' => $step->status->badgeClasses(),
                    'icon' => $step->status->icon(),
                    'sequence' => $step->sequence,
                    'elapsed' => $step->elapsedSeconds(),
                    'command' => $step->command,
                    'log' => $step->log,
                ])
                ->values()
                ->all(),
        ];
    }
}
