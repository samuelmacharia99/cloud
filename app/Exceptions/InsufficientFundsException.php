<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientFundsException extends RuntimeException
{
    public function __construct(
        public float $required,
        public float $available,
    ) {
        parent::__construct(
            "Insufficient funds. Required: KES {$required}, Available: KES {$available}"
        );
    }
}
