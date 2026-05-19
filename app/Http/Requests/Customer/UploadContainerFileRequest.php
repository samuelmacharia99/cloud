<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

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

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => 'required|string|max:500',
            'file' => [
                'required',
                'file',
                'max:51200',
                'mimes:txt,log,json,yaml,yml,xml,html,htm,css,js,ts,php,py,rb,go,java,sh,bash,zsh,conf,cfg,ini,env,md,csv,sql,zip,tar,gz,png,jpg,jpeg,gif,svg,woff,woff2,ttf,eot',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (!($value instanceof UploadedFile)) {
                        return;
                    }
                    $ext = strtolower($value->getClientOriginalExtension());
                    if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
                        $fail("Files with the .{$ext} extension are not permitted.");
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'path.required' => 'Upload path is required',
            'path.max' => 'Path cannot exceed 500 characters',
            'file.required' => 'File is required',
            'file.max' => 'File cannot exceed 50 MB',
            'file.mimes' => 'File type is not allowed. Please upload a permitted file type.',
        ];
    }
}
