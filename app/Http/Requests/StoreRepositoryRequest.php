<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RepositoryType;
use App\Models\Repository;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreRepositoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Repository::class) === true;
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
            'core_version' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+\.[0-9]+$/'],
            'in_use' => ['sometimes', 'boolean'],
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
            'core_version.regex' => 'Use a MediaWiki version like 1.45.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = RepositoryType::tryFrom((string) $this->input('type'));

            // A core version row *is* the version directory, so it needs one.
            if ($type === RepositoryType::Core && $this->input('core_version') === null) {
                $validator->errors()->add('core_version', 'A core version is required when registering MediaWiki core.');
            }

            $duplicate = Repository::query()
                ->where('type', (string) $this->input('type'))
                ->where('name', (string) $this->input('name'))
                ->where('core_version', $this->input('core_version'))
                ->when($this->route('repository') !== null, function ($query) {
                    $repository = $this->route('repository');

                    $query->whereKeyNot($repository instanceof Repository ? $repository->getKey() : $repository);
                })
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('name', 'That repository is already registered for this version.');
            }
        });
    }
}
