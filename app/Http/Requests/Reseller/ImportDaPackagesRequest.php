<?php

namespace App\Http\Requests\Reseller;

use Illuminate\Foundation\Http\FormRequest;

class ImportDaPackagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->is_reseller);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
