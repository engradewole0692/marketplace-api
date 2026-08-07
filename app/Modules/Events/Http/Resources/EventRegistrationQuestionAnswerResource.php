<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventRegistrationQuestionAnswer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventRegistrationQuestionAnswer */
final class EventRegistrationQuestionAnswerResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'question_id' => $this->question?->uuid,
      'field_key' => $this->question?->field_key,
      'answer_text' => $this->answer_text,
      'answer_json' => $this->answer_json,
    ];
  }
}
