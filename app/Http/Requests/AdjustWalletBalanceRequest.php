<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustWalletBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin ?? false;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|not_in:0',
            'reason' => 'required|string|min:10|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'amount.not_in' => 'Amount cannot be zero',
            'reason.min' => 'Reason must be at least 10 characters',
            'reason.max' => 'Reason must not exceed 500 characters',
        ];
    }
}
