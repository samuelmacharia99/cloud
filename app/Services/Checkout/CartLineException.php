<?php

namespace App\Services\Checkout;

use DomainException;

/**
 * A cart line could not be built. Carries the HTTP status the caller should
 * answer with (403 for a tenancy refusal, 422 for an unavailable item) and
 * a message written for the customer.
 */
final class CartLineException extends DomainException
{
    public function __construct(string $message, private int $status = 422)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
