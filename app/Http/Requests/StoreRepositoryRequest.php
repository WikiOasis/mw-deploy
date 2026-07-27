<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RefMode;
use App\Enums\RepositoryType;
use App\Models\Repository;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreRepositoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $repository = $this->route('repository');

        return $repository instanceof Repository
            ? $this->user()?->can('update', $repository) === true
            : $this->user()?->can('create', Repository::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._\-]+$/'],
            'type' => ['required', Rule::enum(RepositoryType::class)],
            // Only https and ssh remotes; a local path or a git:// URL would let
            // the registry point the clone at something unexpected.
            'git_url' => ['required', 'string', 'max:500', 'regex:#^(https://[^\s]+|git@[^\s:]+:[^\s]+)$#'],
            'default_branch' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._\/\-]+$/'],
            'in_use' => ['sometimes', 'boolean'],

            // Which core versions to add a checkout in. Empty means unversioned,
            // i.e. a top-level clone.
            'versions' => ['sometimes', 'array'],
            'versions.*' => ['integer', Rule::exists('mediawiki_versions', 'id')],

            // Per-version pin, keyed by version id.
            'refs' => ['sometimes', 'array'],
            'refs.*.ref_mode' => ['sometimes', Rule::enum(RefMode::class)],
            'refs.*.ref' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\/\-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'Use only letters, digits, dots, dashes and underscores — this becomes a directory name.',
            'git_url.regex' => 'Give an https:// or git@host:path remote.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = RepositoryType::tryFrom((string) $this->input('type'));

            if ($type === null) {
                return;
            }

            // Core is the version directory itself — it is created by cutting a
            // version, not by registering a repository into one.
            if ($type === RepositoryType::Core && $this->input('versions', []) !== []) {
                $validator->errors()->add(
                    'versions',
                    'MediaWiki core is registered once; its per-version checkouts come from creating a version.',
                );
            }

            if ($type->isVersioned() && $type !== RepositoryType::Core && $this->input('versions', []) === []) {
                $validator->errors()->add(
                    'versions',
                    'Choose at least one core version to add this to, or it will be cloned at the top level '
                    .'outside every version tree.',
                );
            }

            $duplicate = Repository::query()
                ->where('type', $type->value)
                ->where('name', (string) $this->input('name'))
                ->when($this->route('repository') !== null, function ($query) {
                    $repository = $this->route('repository');

                    $query->whereKeyNot($repository instanceof Repository ? $repository->getKey() : $repository);
                })
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('name', 'A '.$type->label().' called that is already registered.');
            }
        });
    }

    /**
     * @return list<int>
     */
    public function versionIds(): array
    {
        return array_map('intval', (array) $this->input('versions', []));
    }

    /**
     * @return array<int, array{ref_mode?: string, ref?: string|null}>
     */
    public function refOverrides(): array
    {
        $overrides = [];

        foreach ((array) $this->input('refs', []) as $versionId => $override) {
            $overrides[(int) $versionId] = [
                'ref_mode' => is_string($override['ref_mode'] ?? null) ? $override['ref_mode'] : null,
                'ref' => is_string($override['ref'] ?? null) ? $override['ref'] : null,
            ];
        }

        return $overrides;
    }
}
