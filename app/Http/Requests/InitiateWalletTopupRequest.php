<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitiateWalletTopupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_reseller ?? false;
    }

    public function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $phone = $this->phone;
            if (!str_starts_with($phone, '+')) {
                if (str_starts_with($phone, '254')) {
                    $phone = '+' . $phone;
                } elseif (str_starts_with($phone, '0')) {
                    $phone = '+254' . substr($phone, 1);
                }
            }
            $this->merge(['phone' => $phone]);
        }
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:1500|max:50000',
            'phone' => 'required|string|regex:/^\+254\d{9}$/',
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min' => 'Minimum top-up amount is KES 1,500',
            'amount.max' => 'Maximum top-up amount is KES 50,000',
            'phone.regex' => 'Phone number must be in format: +254XXXXXXXXX',
        ];
    }
}
