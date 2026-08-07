<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Resources;

use App\Modules\Cms\Models\CmsSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CmsSetting */
final class CmsSettingResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'group' => $this->group,
      'key' => $this->key,
      'value' => $this->value,
      'is_public' => $this->is_public,
    ];
  }
}
