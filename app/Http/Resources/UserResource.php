<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
  /**
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->id,
      'uuid' => $this->uuid,
      'first_name' => $this->first_name,
      'last_name' => $this->last_name,
      'display_name' => $this->display_name,
      'name' => $this->name,
      'email' => $this->email,
      'username' => $this->username,
      'phone' => $this->phone,
      'avatar' => $this->avatar,
      'avatar_media_id' => $this->whenLoaded('avatarMedia', fn () => $this->avatarMedia?->uuid),
      'avatar_url' => $this->avatarUrl(),
      'status' => $this->status()->value,
      'must_change_password' => (bool) $this->must_change_password,
      'email_verified' => $this->hasVerifiedEmail(),
      'email_verified_at' => $this->email_verified_at?->toIso8601String(),
      'timezone' => $this->timezone,
      'locale' => $this->locale,
      'last_login_at' => $this->last_login_at?->toIso8601String(),
      'last_login_ip' => $this->last_login_ip,
      'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('slug')),
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
