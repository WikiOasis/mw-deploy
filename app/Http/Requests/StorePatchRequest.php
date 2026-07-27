<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Patch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $patch = $this->route('patch');

        return $patch instanceof Patch
            ? $this->user()?->can('update', $patch) === true
            : $this->user()?->can('create', Patch::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isUpdate = $this->route('patch') instanceof Patch;

        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'target_repo_id' => ['nullable', 'integer', Rule::exists('repositories', 'id')],
            // Relative to the MediaWiki root, and not allowed to climb out of it.
            'target_path' => ['required', 'string', 'max:255', 'regex:#^[A-Za-z0-9._\-]+(/[A-Za-z0-9._\-]+)*$#', 'not_regex:#(^|/)\.\.(/|$)#'],
            'format' => ['required', 'in:unified,git'],
            'active' => ['sometimes', 'boolean'],
            // The stored file is only replaced when a new one is uploaded, so an
            // edit that just fixes a typo in the description keeps the patch.
            'patch_file' => [$isUpdate ? 'nullable' : 'required', 'file', 'max:2048', 'mimetypes:text/plain,text/x-diff,text/x-patch,application/octet-stream'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_path.regex' => 'Give a path relative to the MediaWiki root, e.g. versions/1.45/extensions/Echo.',
            'target_path.not_regex' => 'The target path may not contain "..".',
            'patch_file.max' => 'Patch files are limited to 2 MB.',
        ];
    }
}
