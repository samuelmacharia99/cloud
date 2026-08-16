<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClearResellerWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin ?? false;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:500'],
            'confirm_removal' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min' => 'Enter an amount greater than zero.',
            'confirm_removal.accepted' => 'Confirm that you want to deduct this amount from the reseller’s wallet.',
        ];
    }
}
