<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class CreateContainerDirectoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => 'required|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'path.required' => 'Directory path is required',
            'path.max' => 'Path cannot exceed 500 characters',
        ];
    }
}
