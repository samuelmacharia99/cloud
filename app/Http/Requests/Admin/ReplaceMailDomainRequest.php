<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Replace an email plan's mail domain and destroy the old one. The operator
 * types the domain that is about to be deleted, so the mail it holds cannot
 * be lost to a mis-click.
 */
class ReplaceMailDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->isAdmin() ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/'],
            'confirm_current_domain' => ['nullable', 'string', 'max:190'],
            'understood' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'domain.required' => 'Enter the new mail domain.',
            'domain.regex' => 'Enter a domain such as school.co.ke.',
            'understood.accepted' => 'Confirm that the mail on the current domain is destroyed.',
        ];
    }
}
