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
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'confirm_removal' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.min' => 'Explain why the complete wallet balance is being removed (at least 10 characters).',
            'confirm_removal.accepted' => 'Confirm that you want to remove the reseller’s entire wallet balance.',
        ];
    }
}
