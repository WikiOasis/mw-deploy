<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Enums\DeploymentIntent;
use App\Enums\DeploymentStatus;
use App\Enums\RefType;
use App\Enums\RepoAction;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\Patch;
use App\Models\User;
use App\Support\DeploymentOptions;
use Illuminate\Support\Facades\DB;

final class CreateDeployment
{
    /**
     * @param  array<int, array{repository_version_id: int, action: string, ref_value: ?string, ref_type?: ?string}>  $refs
     * @param  list<int>  $patchIds
     */
    public function __invoke(
        User $actor,
        array $refs,
        array $patchIds,
        DeploymentOptions $options,
        DeploymentIntent $intent = DeploymentIntent::Deploy,
        bool $dispatch = true,
    ): Deployment {
        $deployment = DB::transaction(function () use ($actor, $refs, $patchIds, $options, $intent): Deployment {
            $deployment = Deployment::query()->create([
                'created_by' => $actor->getKey(),
                'status' => DeploymentStatus::Pending->value,
                'intent' => $intent->value,
                'options' => $options->toArray(),
            ]);

            foreach ($refs as $ref) {
                $action = RepoAction::tryFrom((string) ($ref['action'] ?? '')) ?? RepoAction::Deploy;
                $value = $action === RepoAction::Undeploy
                    ? null
                    : trim((string) ($ref['ref_value'] ?? ''));

                $deployment->repoRefs()->create([
                    'repository_version_id' => (int) $ref['repository_version_id'],
                    'action' => $action->value,
                    // A SHA typed into the branch field is still a commit.
                    'ref_type' => $value === null || $value === ''
                        ? null
                        : RefType::reconcile($ref['ref_type'] ?? null, $value)->value,
                    'ref_value' => $value === '' ? null : $value,
                ]);
            }

            // Patches are only meaningful on the way in; a removal has nothing
            // left to patch, and a staging sync ships whatever is already there.
            if ($intent->carriesPatches()) {
                foreach (Patch::query()->whereIn('id', $patchIds)->get() as $patch) {
                    $deployment->deploymentPatches()->create([
                        'patch_id' => $patch->getKey(),
                        'applied' => false,
                    ]);
                }
            }

            return $deployment;
        });

        if ($dispatch) {
            RunDeployment::dispatch($deployment->getKey());
        }

        return $deployment;
    }
}
