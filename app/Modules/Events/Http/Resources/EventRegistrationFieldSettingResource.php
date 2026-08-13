<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventRegistrationFieldSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventRegistrationFieldSetting */
final class EventRegistrationFieldSettingResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'field_key' => $this->field_key,
      'label' => $this->label,
      'is_enabled' => $this->is_enabled,
      'is_required' => $this->is_required,
      'sort_order' => $this->sort_order,
    ];
  }
}
