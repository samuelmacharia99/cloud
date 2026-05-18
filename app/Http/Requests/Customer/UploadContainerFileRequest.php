<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class UploadContainerFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => 'required|string|max:500',
            'file' => 'required|file|max:51200',
        ];
    }

    public function messages(): array
    {
        return [
            'path.required' => 'Upload path is required',
            'path.max' => 'Path cannot exceed 500 characters',
            'file.required' => 'File is required',
            'file.max' => 'File cannot exceed 50 MB',
        ];
    }
}
