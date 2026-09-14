<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class ExtractContainerArchiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => BatchContainerPathsRequest::pathRules(),
            'destination' => array_merge(['nullable'], array_slice(BatchContainerPathsRequest::pathRules(), 1)),
            'delete_archive' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'path.required' => 'Archive path is required',
            'path.regex' => 'Path must start with "/"',
            'path.not_regex' => 'Path contains invalid characters or traversal segments',
            'destination.regex' => 'Destination must start with "/"',
            'destination.not_regex' => 'Destination contains invalid characters or traversal segments',
        ];
    }

    public function archivePath(): string
    {
        return (string) $this->validated('path');
    }

    /**
     * Destination folder; defaults to the archive's own folder.
     */
    public function destination(): string
    {
        $destination = $this->validated('destination');
        if (is_string($destination) && $destination !== '') {
            return $destination;
        }

        $parent = dirname($this->archivePath());

        return $parent === '.' || $parent === '' ? '/' : $parent;
    }

    public function deleteArchive(): bool
    {
        return $this->boolean('delete_archive');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'path' => BatchContainerPathsRequest::normalizePath($this->input('path')),
            'destination' => BatchContainerPathsRequest::normalizePath($this->input('destination')),
        ]);
    }
}
