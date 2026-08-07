<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventFaq;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventFaq */
final class EventFaqResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'question' => $this->question,
      'answer' => $this->answer,
      'sort_order' => $this->sort_order,
      'is_active' => $this->is_active,
    ];
  }
}
