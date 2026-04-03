<?php

namespace App\Http\Requests;

use App\Enums\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->is_admin;
    }

    public function rules(): array
    {
        $payment = $this->route('payment');

        return [
            'status' => [
                'required',
                'string',
                Rule::enum(PaymentStatus::class),
                // Only allow specific status transitions
                $this->validateStatusTransition($payment),
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
            'status.required' => 'Payment status is required.',
            'notes.max' => 'Notes cannot exceed 1000 characters.',
        ];
    }

    /**
     * Validate allowed status transitions.
     * Payment statuses are generally immutable after creation, except for reversal.
     */
    private function validateStatusTransition($payment): callable
    {
        return function ($attribute, $value, $fail) use ($payment) {
            $currentStatus = $payment->status;
            $newStatus = PaymentStatus::tryFrom($value);

            if (!$newStatus) {
                return;
            }

            // Completed payments can only be transitioned to Reversed
            if ($currentStatus === PaymentStatus::Completed && $newStatus !== PaymentStatus::Reversed) {
                $fail('Completed payments can only be reversed, not changed to ' . $newStatus->label());
            }

            // Failed payments cannot be changed
            if ($currentStatus === PaymentStatus::Failed) {
                $fail('Failed payments cannot be modified.');
            }

            // Reversed payments cannot be changed
            if ($currentStatus === PaymentStatus::Reversed) {
                $fail('Reversed payments cannot be modified.');
            }

            // Pending can transition to Completed or Failed
            if ($currentStatus === PaymentStatus::Pending) {
                if (!in_array($newStatus, [PaymentStatus::Completed, PaymentStatus::Failed])) {
                    $fail('Pending payments can only be marked as completed or failed.');
                }
            }
        };
    }
}
