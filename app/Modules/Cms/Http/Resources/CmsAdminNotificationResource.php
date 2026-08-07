<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Resources;

use App\Modules\Cms\Models\CmsAdminNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CmsAdminNotification */
final class CmsAdminNotificationResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'type' => $this->type->value,
      'title' => $this->title,
      'message' => $this->message,
      'data' => $this->data,
      'read_at' => $this->read_at?->toIso8601String(),
      'created_at' => $this->created_at?->toIso8601String(),
    ];
  }
}
