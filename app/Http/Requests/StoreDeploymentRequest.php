<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DeploymentIntent;
use App\Enums\RefType;
use App\Enums\RepoAction;
use App\Enums\TargetRole;
use App\Models\Deployment;
use App\Models\DeployTarget;
use App\Models\Patch;
use App\Models\RepositoryVersion;
use App\Support\DeploymentOptions;
use App\Support\Permissions;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Backs both the review screen and the actual create, so what an operator confirms
 * is validated by exactly the same rules that admit the deployment.
 *
 * This is the UI half of "check permissions in both places"; the job re-derives
 * the same answers through DeploymentAuthorizer.
 */
final class StoreDeploymentRequest extends FormRequest
{
    /** @var Collection<int, RepositoryVersion>|null */
    private ?Collection $resolvedCheckouts = null;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return match ($this->intent()) {
            DeploymentIntent::Undeploy => $user->hasAnyPermission(Permissions::anyUndeploy()),
            DeploymentIntent::SyncStaging => $user->can('syncStaging', Deployment::class),
            default => $user->can('create', Deployment::class),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $intent = $this->intent();
        $isUndeploy = $intent === DeploymentIntent::Undeploy;
        $selectsCheckouts = $intent->selectsCheckouts();

        return [
            'intent' => ['sometimes', Rule::in([
                DeploymentIntent::Deploy->value,
                DeploymentIntent::Undeploy->value,
                DeploymentIntent::SyncStaging->value,
            ])],

            // A staging sync deploys the tree as it stands. There is nothing to
            // select, and accepting a selection would imply the operator's choice
            // narrowed what ships when it does not.
            'items' => $selectsCheckouts
                ? ['required', 'array', 'min:1']
                : ['prohibited'],
            // Existence is checked in withValidator() against the one query that
            // already loads these models, not with a per-item `exists` rule. An
            // upgrade submits every extension in a version at once — a hundred-odd
            // line items — and a rule per item is a query per item.
            'items.*.repository_version_id' => ['required', 'integer'],

            // A removal has no ref to check out, and accepting one would imply the
            // operator had a say in something that is ignored. The string/regex
            // checks must live inside this branch, not alongside it — the review
            // step echoes an undeploy's items back with an explicit ref_value of
            // null, and an unconditional 'string' rule fails a null value outright.
            'items.*.ref_value' => $isUndeploy
                ? ['prohibited']
                : ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\/\-]+$/'],
            'items.*.ref_type' => ['sometimes', 'nullable', Rule::enum(RefType::class)],

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
            'items.required' => 'Select at least one checkout.',
            'items.prohibited' => 'A staging sync deploys the whole tree, so nothing is selected for it.',
            'items.*.ref_value.regex' => 'A git ref may only contain letters, digits, dots, slashes, dashes and underscores.',
            'items.*.ref_value.prohibited' => 'An undeploy has no ref to check out.',
        ];
    }

    /**
     * Permission checks that need the resolved models, run after the basic rules
     * pass so error messages can name the offending checkout.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();

            if ($user === null) {
                return;
            }

            $isUndeploy = $this->intent() === DeploymentIntent::Undeploy;
            $byId = $this->checkouts()->keyBy('id');

            foreach ((array) $this->input('items', []) as $key => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id = (int) ($item['repository_version_id'] ?? 0);

                // Missing or non-numeric: the rules above have already said so, and
                // saying it twice on one field is noise.
                if ($id === 0) {
                    continue;
                }

                $checkout = $byId->get($id);

                if ($checkout === null) {
                    $validator->errors()->add(
                        "items.{$key}.repository_version_id",
                        'That checkout no longer exists.',
                    );

                    continue;
                }

                $repository = $checkout->repository;

                if ($repository === null) {
                    $validator->errors()->add("items.{$key}.repository_version_id", 'That repository no longer exists.');

                    continue;
                }

                if ($isUndeploy) {
                    if (! $user->canUndeployRepository($repository)) {
                        $validator->errors()->add(
                            "items.{$key}.repository_version_id",
                            'You do not have permission to remove '.$checkout->displayName().'.',
                        );
                    }

                    if (! $checkout->isPresent()) {
                        $validator->errors()->add(
                            "items.{$key}.repository_version_id",
                            $checkout->displayName().' is already undeployed.',
                        );
                    }

                    continue;
                }

                if (! $user->canDeployRepository($repository)) {
                    $validator->errors()->add(
                        "items.{$key}.repository_version_id",
                        'You do not have permission to deploy '.$checkout->displayName().'.',
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

    public function intent(): DeploymentIntent
    {
        return DeploymentIntent::tryFrom((string) $this->input('intent', 'deploy')) ?? DeploymentIntent::Deploy;
    }

    public function action(): RepoAction
    {
        return $this->intent()->defaultAction();
    }

    /**
     * @return array<int, array{repository_version_id: int, action: string, ref_type: ?string, ref_value: ?string}>
     */
    public function items(): array
    {
        // A staging sync has no line items at all: the tree as it stands is the
        // selection.
        if (! $this->intent()->selectsCheckouts()) {
            return [];
        }

        $action = $this->action();
        $items = [];

        foreach ($this->validated('items') as $item) {
            $value = $action === RepoAction::Undeploy
                ? null
                : trim((string) ($item['ref_value'] ?? ''));

            $items[] = [
                'repository_version_id' => (int) $item['repository_version_id'],
                'action' => $action->value,
                'ref_type' => $value === null || $value === ''
                    ? null
                    : RefType::reconcile($item['ref_type'] ?? null, $value)->value,
                'ref_value' => $value === '' ? null : $value,
            ];
        }

        return $items;
    }

    /**
     * @return Collection<int, RepositoryVersion>
     */
    public function checkouts(): Collection
    {
        return $this->resolvedCheckouts ??= RepositoryVersion::query()
            ->with(['repository', 'mediawikiVersion'])
            ->whereIn('id', $this->submittedCheckoutIds())
            ->get();
    }

    /**
     * @return list<int>
     */
    private function submittedCheckoutIds(): array
    {
        return collect((array) $this->input('items', []))
            ->map(fn ($item) => is_array($item) ? (int) ($item['repository_version_id'] ?? 0) : 0)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Patch>
     */
    public function patches(): Collection
    {
        // Patches are meaningless on a removal, and a staging sync ships whatever
        // is already applied on disk, so they are dropped rather than validated
        // away — the wizard does not offer them under either intent.
        if (! $this->intent()->carriesPatches()) {
            return Patch::query()->whereRaw('1 = 0')->get();
        }

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
