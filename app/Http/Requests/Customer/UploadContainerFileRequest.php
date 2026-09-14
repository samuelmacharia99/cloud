<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * Upload one or more files into a directory.
 *
 * New clients send `path` (the target directory) plus `files[]`. The older
 * single-file shape, `path` as the full target file path plus `file`, is
 * still accepted.
 */
class UploadContainerFileRequest extends FormRequest
{
    /**
     * Known dangerous extensions that may allow server-side code execution.
     */
    private const BLOCKED_EXTENSIONS = [
        'php3', 'php4', 'php5', 'php7', 'phtml', 'phar',
        'asp', 'aspx', 'jsp', 'jspx', 'cgi', 'pl',
        'exe', 'bat', 'cmd', 'ps1', 'vbs',
    ];

    private const ALLOWED_MIMES = 'txt,log,json,yaml,yml,xml,html,htm,css,js,ts,php,py,rb,go,java,sh,bash,zsh,conf,cfg,ini,env,md,csv,sql,zip,tar,gz,tgz,png,jpg,jpeg,gif,svg,webp,ico,woff,woff2,ttf,eot,pdf';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxMb = (int) config('security.container_file_upload.max_size_mb', 100);
        $maxKb = max(1, $maxMb) * 1024;

        $fileRules = [
            'file',
            'max:'.$maxKb,
            'mimes:'.self::ALLOWED_MIMES,
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! ($value instanceof UploadedFile)) {
                    return;
                }
                $name = $value->getClientOriginalName();
                if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0") || $name === '.' || $name === '..') {
                    $fail('The file name is not allowed.');

                    return;
                }
                $ext = strtolower($value->getClientOriginalExtension());
                if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
                    $fail("Files with the .{$ext} extension are not permitted.");
                }
            },
        ];

        return [
            'path' => BatchContainerPathsRequest::pathRules(),
            'file' => array_merge(['nullable'], $fileRules),
            'files' => ['nullable', 'array', 'max:50'],
            'files.*' => $fileRules,
            'extract' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->hasFile('file') && ! $this->hasFile('files')) {
                $validator->errors()->add('files', 'Choose at least one file to upload.');
            }
        });
    }

    public function messages(): array
    {
        $maxMb = (int) config('security.container_file_upload.max_size_mb', 100);

        return [
            'path.required' => 'Upload path is required',
            'path.max' => 'Path cannot exceed 500 characters',
            'path.regex' => 'Path must start with "/"',
            'path.not_regex' => 'Path contains invalid characters or traversal segments',
            'file.max' => 'File cannot exceed '.$maxMb.' MB',
            'file.mimes' => 'File type is not allowed. Please upload a permitted file type.',
            'files.max' => 'Upload at most 50 files at once.',
            'files.*.max' => 'Each file cannot exceed '.$maxMb.' MB',
            'files.*.mimes' => 'A file type is not allowed. Please upload permitted file types only.',
        ];
    }

    /**
     * Files to store, keyed by their sanitised client name.
     *
     * @return list<UploadedFile>
     */
    public function uploadedFiles(): array
    {
        $files = [];
        if ($this->hasFile('files')) {
            foreach ((array) $this->file('files') as $file) {
                if ($file instanceof UploadedFile) {
                    $files[] = $file;
                }
            }
        }
        if ($this->hasFile('file') && $this->file('file') instanceof UploadedFile) {
            $files[] = $this->file('file');
        }

        return $files;
    }

    /**
     * Legacy shape: a single `file` whose `path` is the full target file path.
     */
    public function isLegacySingleFile(): bool
    {
        return $this->hasFile('file') && ! $this->hasFile('files');
    }

    public function shouldExtract(): bool
    {
        return $this->boolean('extract');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['path' => BatchContainerPathsRequest::normalizePath($this->input('path'))]);
    }
}
