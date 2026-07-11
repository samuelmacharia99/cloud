<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeleteContainerEnvironmentRequest extends FormRequest
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
            'keys' => ['required', 'array', 'min:1', 'max:50'],
            'keys.*' => ['required', 'string', 'max:100'],
            'restart' => ['sometimes', 'boolean'],
        ];
    }
}
