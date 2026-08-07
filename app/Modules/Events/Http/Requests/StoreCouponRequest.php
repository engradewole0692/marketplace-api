<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use App\Modules\Events\Enums\CouponDiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCouponRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'code' => ['required', 'string', 'max:80'],
      'discount_type' => ['required', Rule::enum(CouponDiscountType::class)],
      'discount_value' => ['required', 'numeric', 'min:0'],
      'max_uses' => ['nullable', 'integer', 'min:1'],
      'starts_at' => ['nullable', 'date'],
      'ends_at' => ['nullable', 'date'],
      'is_active' => ['boolean'],
    ];
  }
}
