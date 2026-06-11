<?php

namespace App\Rules;

use App\Support\Countries;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidCountryCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Countries::isValidCode(is_string($value) ? $value : null)) {
            $fail('Please select a valid country.');
        }
    }
}
