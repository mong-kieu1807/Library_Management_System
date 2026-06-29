<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Unified password policy:
 *   - Tối thiểu 8 ký tự
 *   - Ít nhất 1 chữ hoa
 *   - Ít nhất 1 chữ thường
 *   - Ít nhất 1 chữ số
 *   - Ít nhất 1 ký tự đặc biệt trong tập @$!%*?&
 */
class StrongPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (
            !is_string($value) ||
            !preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&]).{8,}$/', $value)
        ) {
            $fail('Mật khẩu phải có ít nhất 8 ký tự, gồm chữ hoa, chữ thường, số và ký tự đặc biệt (@$!%*?&).');
        }
    }
}
