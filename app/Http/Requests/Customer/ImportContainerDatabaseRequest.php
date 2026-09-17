<?php

namespace App\Http\Requests\Customer;

use App\Services\Provisioning\ChunkedUploadStore;
use App\Services\Provisioning\ContainerSqlDumpImportService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ImportContainerDatabaseRequest extends FormRequest
{
    public const CHUNK_BYTES = ChunkedUploadStore::CHUNK_BYTES;

    public const CHUNK_MAX_KB = ChunkedUploadStore::CHUNK_MAX_KB;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * How many CHUNK_BYTES slices the largest allowed dump takes, plus one for
     * the remainder, so the cap on chunk counts follows the size ceiling.
     */
    public static function maxChunks(int $maxMb): int
    {
        return ChunkedUploadStore::maxChunks($maxMb);
    }

    public function rules(): array
    {
        $chunking = $this->filled('chunk_index');
        $maxMb = max(1, (int) config('security.container_db_import.max_size_mb', 1024));
        $maxKb = $chunking ? self::CHUNK_MAX_KB : $maxMb * 1024;
        $maxChunks = self::maxChunks($maxMb);

        return [
            'file' => [
                'required',
                'file',
                'max:'.$maxKb,
            ],
            'upload_id' => ['required_with:chunk_index', 'nullable', 'regex:/^[a-f0-9]{16,64}$/'],
            'chunk_index' => ['nullable', 'integer', 'min:0', 'max:'.($maxChunks - 1)],
            'chunk_total' => ['required_with:chunk_index', 'nullable', 'integer', 'min:1', 'max:'.$maxChunks],
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
            'file.max' => 'SQL file cannot exceed '.(int) config('security.container_db_import.max_size_mb', 1024).' MB.',
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
