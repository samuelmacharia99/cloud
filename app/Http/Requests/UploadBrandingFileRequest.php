<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadBrandingFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->is_reseller;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|image|mimes:jpeg,png,gif,webp,svg,ico|max:2048',
            'type' => 'required|in:logo,favicon',
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Please select a file to upload.',
            'file.image' => 'The file must be an image.',
            'file.mimes' => 'The file must be a JPEG, PNG, GIF, WebP, SVG, or ICO image.',
            'file.max' => 'The file must not exceed 2MB.',
            'type.required' => 'File type is required.',
            'type.in' => 'File type must be logo or favicon.',
        ];
    }
}
