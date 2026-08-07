<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventResource as EventResourceModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventResourceModel */
final class EventResourceItemResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'title' => $this->title,
      'resource_type' => $this->resource_type,
      'description' => $this->description,
      'resource_url' => $this->resource_url,
      'is_public' => $this->is_public,
      'sort_order' => $this->sort_order,
    ];
  }
}
