<?php

namespace App\Http\Requests\Reseller;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A reseller corrects which DirectAdmin user and server one of their
 * shared-hosting services points at, then retries the move.
 */
class RelinkDaAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->is_reseller ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'directadmin_username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'node_id' => ['nullable', 'integer', Rule::exists('nodes', 'id')->where('type', 'directadmin')],
            'retry' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'directadmin_username.required' => 'Enter the DirectAdmin username.',
            'directadmin_username.regex' => 'A DirectAdmin username has only letters, digits, dots, dashes and underscores.',
        ];
    }
}
