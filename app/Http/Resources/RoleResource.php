<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Role
 */
final class RoleResource extends JsonResource
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
      'guard_name' => $this->guard_name,
      'description' => $this->description,
      'is_system' => $this->is_system,
      'users_count' => $this->whenCounted('users'),
      'permissions_count' => $this->whenCounted('permissions'),
      'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
