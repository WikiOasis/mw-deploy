<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RefType;
use App\Enums\TargetRole;
use App\Models\Deployment;
use App\Models\DeployTarget;
use App\Models\Patch;
use App\Models\Repository;
use App\Support\DeploymentOptions;
use App\Support\Permissions;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Backs both the review screen and the actual create, so what an operator
 * confirms is validated by exactly the same rules that admit the deployment.
 *
 * This is the UI half of "check permissions in both places"; the job re-derives
 * the same answers through DeploymentAuthorizer.
 */
final class StoreDeploymentRequest extends FormRequest
{
    /** @var Collection<int, Repository>|null */
    private ?Collection $resolvedRepositories = null;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Deployment::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'refs' => ['required', 'array', 'min:1'],
            'refs.*.repository_id' => ['required', 'integer', Rule::exists('repositories', 'id')->where('active', true)],
            'refs.*.ref_type' => ['required', Rule::enum(RefType::class)],
            'refs.*.ref_value' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\/\-]+$/'],

            'patches' => ['sometimes', 'array'],
            'patches.*' => ['integer', Rule::exists('patches', 'id')->where('active', true)],

            'servers' => ['sometimes', 'array'],
            'servers.*' => ['string', Rule::exists('deploy_targets', 'hostname')
                ->where('active', true)
                ->where('role', TargetRole::Appserver->value)],

            'parallel' => ['required', 'integer', 'min:1', 'max:'.(int) config('mwdeploy.rollout.max_parallel', 8)],
            'force' => ['sometimes', 'boolean'],
            'l10n' => ['sometimes', 'boolean'],
            'rollout' => ['sometimes', 'boolean'],
            'staging_only' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'refs.required' => 'Select at least one repository to deploy.',
            'refs.*.ref_value.regex' => 'A git ref may only contain letters, digits, dots, slashes, dashes and underscores.',
        ];
    }

    /**
     * Permission checks that need the resolved models, run after the basic rules
     * pass so error messages can name the offending repository.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();

            if ($user === null) {
                return;
            }

            // Keyed by the submitted array key (which may be a repository id
            // rather than a numeric index) so errors land on the right field.
            $byId = $this->repositories()->keyBy('id');

            foreach ((array) $this->input('refs', []) as $key => $ref) {
                $repository = $byId->get((int) ($ref['repository_id'] ?? 0));

                if ($repository === null) {
                    continue;
                }

                if (! $user->canDeployRepository($repository)) {
                    $validator->errors()->add(
                        'refs.'.$key.'.repository_id',
                        'You do not have permission to deploy '.$repository->displayName().'.',
                    );
                }
            }

            if ($this->boolean('force') && ! $user->hasPermission(Permissions::DEPLOY_FORCE_FLAG)) {
                $validator->errors()->add('force', 'Only an administrator may skip the canary check.');
            }

            if (! $this->boolean('staging_only') && ! $user->hasPermission(Permissions::DEPLOY_PRODUCTION_SERVERS)) {
                $validator->errors()->add(
                    'staging_only',
                    'You may only run staging-only deployments. Tick "staging only" to continue.',
                );
            }

            if (! $this->boolean('staging_only') && $this->appserverCount() === 0) {
                $validator->errors()->add('servers', 'There are no active appservers to deploy to.');
            }
        });
    }

    /**
     * @return array<int, array{repository_id: int, ref_type: string, ref_value: string}>
     */
    public function refs(): array
    {
        $refs = [];

        foreach ($this->validated('refs') as $ref) {
            $value = trim((string) $ref['ref_value']);

            $refs[] = [
                'repository_id' => (int) $ref['repository_id'],
                'ref_type' => RefType::reconcile((string) $ref['ref_type'], $value)->value,
                'ref_value' => $value,
            ];
        }

        return $refs;
    }

    /**
     * Every repository referenced by the submitted refs.
     *
     * @return Collection<int, Repository>
     */
    public function repositories(): Collection
    {
        return $this->resolvedRepositories ??= Repository::query()
            ->whereKey($this->submittedRepositoryIds())
            ->get();
    }

    /**
     * @return list<int>
     */
    private function submittedRepositoryIds(): array
    {
        return collect((array) $this->input('refs', []))
            ->map(fn ($ref) => (int) ($ref['repository_id'] ?? 0))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Patches selected for this deployment, restricted to active ones.
     *
     * @return Collection<int, Patch>
     */
    public function patches(): Collection
    {
        // whereIn rather than whereKey so an empty selection still returns an
        // Eloquent collection (and so modelKeys() stays available downstream).
        return Patch::query()
            ->active()
            ->whereIn('id', array_map('intval', (array) $this->input('patches', [])))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<int>
     */
    public function patchIds(): array
    {
        return $this->patches()->modelKeys();
    }

    public function options(): DeploymentOptions
    {
        return new DeploymentOptions(
            servers: array_values(array_map('strval', (array) $this->input('servers', []))),
            parallel: (int) $this->input('parallel', (int) config('mwdeploy.rollout.default_parallel', 1)),
            force: $this->boolean('force'),
            l10n: $this->boolean('l10n'),
            rollout: $this->boolean('rollout'),
            stagingOnly: $this->boolean('staging_only'),
        );
    }

    private function appserverCount(): int
    {
        $query = DeployTarget::query()->active()->role(TargetRole::Appserver);

        $servers = (array) $this->input('servers', []);

        if ($servers !== []) {
            $query->whereIn('hostname', $servers);
        }

        return $query->count();
    }
}
