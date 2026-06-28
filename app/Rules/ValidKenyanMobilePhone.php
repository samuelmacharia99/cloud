<?php

namespace App\Rules;

use App\Helpers\PhoneHelper;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidKenyanMobilePhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalized = PhoneHelper::normalize(trim((string) $value));

        if (! preg_match('/^254[17]\d{8}$/', $normalized)) {
            $fail('Please enter a valid Kenyan mobile number (e.g. 0712345678).');
        }
    }
}
