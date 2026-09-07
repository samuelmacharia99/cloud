<?php

namespace App\Http\Requests\Customer;

use App\Services\Provisioning\ContainerSqlDumpImportService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ImportContainerDatabaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $chunking = $this->filled('chunk_index');
        $maxMb = (int) config('security.container_db_import.max_size_mb', 100);
        $maxKb = $chunking ? 2048 : max(1, $maxMb) * 1024;

        return [
            'file' => [
                'required',
                'file',
                'max:'.$maxKb,
            ],
            'upload_id' => ['required_with:chunk_index', 'nullable', 'regex:/^[a-f0-9]{16,64}$/'],
            'chunk_index' => ['nullable', 'integer', 'min:0', 'max:400'],
            'chunk_total' => ['required_with:chunk_index', 'nullable', 'integer', 'min:1', 'max:400'],
            'filename' => ['required_with:chunk_index', 'nullable', 'string', 'max:180'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $file = $this->file('file');
            if (! $file) {
                return;
            }

            $ext = strtolower((string) ($this->input('filename') ?: $file->getClientOriginalExtension()));
            if (str_contains($ext, '.')) {
                $ext = strtolower((string) pathinfo($ext, PATHINFO_EXTENSION));
            }
            if (! in_array($ext, ['sql', 'txt'], true)) {
                $validator->errors()->add('file', 'Only .sql files are supported for database import.');
            }
        });
    }

    public function messages(): array
    {
        $importer = app(ContainerSqlDumpImportService::class);
        $phpLimit = $importer->phpUploadLimitLabel();
        $uploadError = $importer->describePhpUploadFailure($this->file('file'));

        return [
            'file.required' => 'Choose a .sql file to import.',
            'file.uploaded' => $uploadError ?: (
                'The file failed to upload. PHP on this panel allows '.$phpLimit
                .'. Retry Import SQL — large dumps are sent in small chunks.'
            ),
            'file.max' => 'SQL file cannot exceed '.(int) config('security.container_db_import.max_size_mb', 100).' MB.',
            'file.mimes' => 'Only .sql files are supported for database import.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            $message = $validator->errors()->first() ?: 'Import failed.';

            throw new HttpResponseException(response()->json([
                'error' => $message,
                'message' => $message,
                'errors' => $validator->errors(),
            ], 422));
        }

        parent::failedValidation($validator);
    }
}
