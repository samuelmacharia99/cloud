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
            'extensions' => ['nullable', 'array'],
            'extensions.*' => [
                'string',
                Rule::in(array_keys(config('php_extensions.extensions', []))),
            ],
        ];
    }
}
