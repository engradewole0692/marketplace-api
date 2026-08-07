<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

final class UpdateSpeakerRequest extends StoreSpeakerRequest
{
  public function rules(): array
  {
    $rules = parent::rules();
    $rules['name'][0] = 'sometimes';

    return $rules;
  }
}
