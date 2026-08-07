<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use App\Modules\Events\Models\Speaker;
use App\Modules\Events\Support\UuidResolver;
use Illuminate\Foundation\Http\FormRequest;

final class StoreSessionRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  protected function prepareForValidation(): void
  {
    UuidResolver::resolve($this, [
      'speaker_id' => Speaker::class,
    ]);
  }

  public function rules(): array
  {
    return [
      'title' => ['required', 'string', 'max:255'],
      'speaker_id' => ['nullable', 'integer', 'exists:speakers,id'],
      'session_type' => ['nullable', 'string', 'max:60'],
      'description' => ['nullable', 'string'],
      'starts_at' => ['nullable', 'date'],
      'ends_at' => ['nullable', 'date'],
      'location' => ['nullable', 'string', 'max:255'],
      'room' => ['nullable', 'string', 'max:255'],
      'track' => ['nullable', 'string', 'max:255'],
      'moderator_user_id' => ['nullable', 'integer', 'exists:users,id'],
      'capacity' => ['nullable', 'integer', 'min:1'],
      'sort_order' => ['nullable', 'integer'],
      'resources_json' => ['nullable', 'array'],
    ];
  }
}
