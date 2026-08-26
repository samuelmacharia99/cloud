<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContainerPhpExtensionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'extension' => [
                'required',
                'string',
                Rule::in(array_keys(config('php_extensions.extensions', []))),
            ],
            'enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'extension.required' => 'Choose a PHP extension to update.',
            'extension.in' => 'That PHP extension is not available on this runtime.',
            'enabled.required' => 'Specify whether the extension should be enabled.',
        ];
    }
}
