<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContainerGitRepositoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_repo_url' => ['required', 'url', 'max:500'],
            'source_repo_branch' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._\\/-]+$/'],
            'source_repo_token' => ['nullable', 'string', 'max:500'],
            'composer_github_token' => ['nullable', 'string', 'max:500'],
            'remove_repo_token' => ['nullable', 'boolean'],
            'remove_composer_auth' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'source_repo_url.required' => 'Repository URL is required.',
            'source_repo_url.url' => 'Repository URL must be a valid HTTPS URL.',
            'source_repo_branch.regex' => 'Branch name contains invalid characters.',
        ];
    }
}
