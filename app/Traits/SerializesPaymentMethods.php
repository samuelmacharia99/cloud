<?php

namespace App\Traits;

use App\Enums\PaymentMethod;

/**
 * Trait for consistent payment method serialization across the application.
 * Provides methods to format payment method data for API responses and views.
 */
trait SerializesPaymentMethods
{
    /**
     * Get payment method data for API/view serialization.
     */
    protected function serializePaymentMethod(PaymentMethod $method): array
    {
        return [
            'value' => $method->value,
            'label' => $method->label(),
            'icon' => $method->icon(),
            'color' => $method->color(),
        ];
    }

    /**
     * Get all payment methods as serialized array.
     */
    protected function allPaymentMethods(): array
    {
        return collect(PaymentMethod::cases())
            ->map(fn (PaymentMethod $method) => $this->serializePaymentMethod($method))
            ->values()
            ->toArray();
    }

    /**
     * Get payment method label.
     */
    protected function getPaymentMethodLabel(string|PaymentMethod $method): string
    {
        if (is_string($method)) {
            $method = PaymentMethod::tryFrom($method);
        }

        return $method?->label() ?? 'Unknown';
    }

    /**
     * Get payment method icon.
     */
    protected function getPaymentMethodIcon(string|PaymentMethod $method): string
    {
        if (is_string($method)) {
            $method = PaymentMethod::tryFrom($method);
        }

        return $method?->icon() ?? 'question-mark';
    }

    /**
     * Get payment method color.
     */
    protected function getPaymentMethodColor(string|PaymentMethod $method): string
    {
        if (is_string($method)) {
            $method = PaymentMethod::tryFrom($method);
        }

        return $method?->color() ?? 'slate';
    }
}
