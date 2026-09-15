<?php

namespace App\Http\Requests\Reseller;

use Illuminate\Foundation\Http\FormRequest;

class CutoverDaConvertBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->is_reseller ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer', 'exists:da_convert_batch_items,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'item_ids.required' => 'Select at least one moved account to cut web DNS.',
        ];
    }
}
