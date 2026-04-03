<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->is_admin;
    }

    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
            'invoice_id' => [
                'nullable',
                'integer',
                'exists:invoices,id',
            ],
            'amount' => [
                'required',
                'numeric',
                'min:0.01',
                'max:999999.99',
            ],
            'currency' => [
                'required',
                'string',
                'size:3',
                'uppercase',
                Rule::in(['KES', 'USD', 'EUR', 'GBP']),
            ],
            'payment_method' => [
                'required',
                'string',
                Rule::enum(PaymentMethod::class),
            ],
            'transaction_reference' => [
                'nullable',
                'string',
                'max:255',
                'unique:payments,transaction_reference',
            ],
            'status' => [
                'required',
                'string',
                Rule::enum(PaymentStatus::class),
            ],
            'paid_at' => [
                'nullable',
                'date',
                'before_or_equal:now',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'Payment must be assigned to a user.',
            'user_id.exists' => 'Selected user does not exist.',
            'amount.required' => 'Payment amount is required.',
            'amount.min' => 'Payment amount must be greater than zero.',
            'currency.size' => 'Currency code must be exactly 3 characters (e.g., KES).',
            'payment_method.required' => 'Payment method is required.',
            'status.required' => 'Payment status is required.',
            'paid_at.before_or_equal' => 'Payment date cannot be in the future.',
        ];
    }

    public function prepareForValidation(): void
    {
        $this->merge([
            'currency' => strtoupper($this->currency ?? 'KES'),
        ]);
    }
}
