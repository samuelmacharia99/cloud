<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RenameCustomerServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }
}
