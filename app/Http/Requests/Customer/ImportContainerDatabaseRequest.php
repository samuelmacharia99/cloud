<?php

namespace App\Http\Requests\Customer;

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
        $maxMb = (int) config('security.container_db_import.max_size_mb', 50);
        $maxKb = max(1, $maxMb) * 1024;

        return [
            'file' => [
                'required',
                'file',
                'max:'.$maxKb,
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $file = $this->file('file');
            if (! $file) {
                return;
            }

            $ext = strtolower((string) $file->getClientOriginalExtension());
            if (! in_array($ext, ['sql', 'txt'], true)) {
                $validator->errors()->add('file', 'Only .sql files are supported for database import.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Choose a .sql file to import.',
            'file.max' => 'SQL file cannot exceed '.(int) config('security.container_db_import.max_size_mb', 50).' MB.',
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
