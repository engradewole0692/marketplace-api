<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventRegistrationQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventRegistrationQuestion */
final class EventRegistrationQuestionResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    $metadata = is_array($this->metadata) ? $this->metadata : [];

    return [
      'id' => $this->uuid,
      'field_key' => $this->field_key,
      'question' => $this->question,
      'label' => $this->question,
      'help_text' => $this->help_text,
      'answer_type' => $this->answer_type,
      'options' => $this->options,
      'is_enabled' => $this->is_enabled,
      'is_required' => $this->is_required,
      'maps_to_member_field' => $this->maps_to_member_field,
      'sort_order' => $this->sort_order,
      'show_on_public' => array_key_exists('show_on_public', $metadata) ? (bool) $metadata['show_on_public'] : true,
      'show_on_quick' => array_key_exists('show_on_quick', $metadata) ? (bool) $metadata['show_on_quick'] : false,
      'metadata' => $metadata,
    ];
  }
}
