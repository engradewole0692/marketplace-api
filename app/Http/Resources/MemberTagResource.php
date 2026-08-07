<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MemberTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MemberTag
 */
final class MemberTagResource extends JsonResource
{
  /**
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->id,
      'uuid' => $this->uuid,
      'name' => $this->name,
      'slug' => $this->slug,
      'color' => $this->color,
    ];
  }
}
