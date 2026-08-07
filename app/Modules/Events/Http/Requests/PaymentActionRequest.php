<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PaymentActionRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'notes' => ['nullable', 'string'],
      'coupon_code' => ['nullable', 'string', 'max:80'],
      'donation_id' => ['nullable', 'integer'],
    ];
  }
}
