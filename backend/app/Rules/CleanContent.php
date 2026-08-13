<?php

namespace App\Rules;

use App\Support\ContentModeration;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CleanContent implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (ContentModeration::containsBlockedLanguage($value)) {
            $fail('Please remove offensive or abusive language from this field.');
        }
    }
}
