<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

final class UpdateEventRequest extends StoreEventRequest
{
  public function rules(): array
  {
    $rules = parent::rules();

    foreach (['title'] as $field) {
      $rules[$field][0] = 'sometimes';
    }

    return $rules;
  }
}
