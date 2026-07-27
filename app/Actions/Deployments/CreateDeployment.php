<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Enums\DeploymentStatus;
use App\Enums\RefType;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\Patch;
use App\Models\User;
use App\Support\DeploymentOptions;
use Illuminate\Support\Facades\DB;

final class CreateDeployment
{
    /**
     * @param  array<int, array{repository_id: int, ref_type: string, ref_value: string}>  $refs
     * @param  list<int>  $patchIds
     */
    public function __invoke(
        User $actor,
        array $refs,
        array $patchIds,
        DeploymentOptions $options,
        bool $dispatch = true,
    ): Deployment {
        $deployment = DB::transaction(function () use ($actor, $refs, $patchIds, $options): Deployment {
            $deployment = Deployment::create([
                'created_by' => $actor->getKey(),
                'status' => DeploymentStatus::Pending->value,
                'options' => $options->toArray(),
            ]);

            foreach ($refs as $ref) {
                $value = trim((string) $ref['ref_value']);

                $deployment->repoRefs()->create([
                    'repository_id' => (int) $ref['repository_id'],
                    // A SHA typed into the branch field is still a commit.
                    'ref_type' => RefType::reconcile((string) $ref['ref_type'], $value)->value,
                    'ref_value' => $value,
                ]);
            }

            foreach (Patch::query()->whereKey($patchIds)->get() as $patch) {
                $deployment->deploymentPatches()->create([
                    'patch_id' => $patch->getKey(),
                    'applied' => false,
                ]);
            }

            return $deployment;
        });

        if ($dispatch) {
            RunDeployment::dispatch($deployment->getKey());
        }

        return $deployment;
    }
}
