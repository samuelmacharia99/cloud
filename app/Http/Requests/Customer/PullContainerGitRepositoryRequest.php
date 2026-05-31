<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class PullContainerGitRepositoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'replace_existing' => ['sometimes', 'boolean'],
            'run_composer' => ['sometimes', 'boolean'],
            'run_migrations' => ['sometimes', 'boolean'],
        ];
    }
}
