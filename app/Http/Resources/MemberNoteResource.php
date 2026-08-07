<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MemberNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MemberNote
 */
final class MemberNoteResource extends JsonResource
{
  /**
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->id,
      'uuid' => $this->uuid,
      'body' => $this->body,
      'is_private' => $this->is_private,
      'author' => new UserResource($this->whenLoaded('author')),
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
