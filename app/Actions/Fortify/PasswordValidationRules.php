<?php

namespace App\Actions\Fortify;

use Closure;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Closure|Password|Rule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::min(12), 'max:72', 'confirmed', function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && strlen($value) > 72) {
                $fail('The password must not exceed 72 bytes.');
            }
        }];
    }
}
