<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class UploadAvatarRequest extends FormRequest
{
  public function authorize(): bool
  {
    return $this->user() !== null;
  }

  /**
   * @return array<string, mixed>
   */
  public function rules(): array
  {
    return [
      'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
    ];
  }
}
