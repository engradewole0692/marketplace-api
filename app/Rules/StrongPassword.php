<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

final class StrongPassword implements ValidationRule
{
  public function validate(string $attribute, mixed $value, Closure $fail): void
  {
    $validator = validator(
      [$attribute => $value],
      [$attribute => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()]],
    );

    if ($validator->fails()) {
      $fail('The :attribute must be at least 12 characters and include uppercase, lowercase, numbers, and symbols.');
    }
  }
}
