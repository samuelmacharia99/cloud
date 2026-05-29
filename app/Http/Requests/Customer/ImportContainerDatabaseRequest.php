<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

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
                'mimes:sql,txt',
                'mimetypes:text/plain,text/x-sql,application/sql,application/octet-stream,application/x-sql',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Choose a .sql file to import.',
            'file.max' => 'SQL file cannot exceed '.(int) config('security.container_db_import.max_size_mb', 50).' MB.',
            'file.mimes' => 'Only .sql files are supported for database import.',
        ];
    }
}
