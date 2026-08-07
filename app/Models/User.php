<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
  /** @use HasFactory<UserFactory> */
  use HasApiTokens;
  use HasFactory;
  use Notifiable;
  use SoftDeletes;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'name',
    'first_name',
    'last_name',
    'display_name',
    'email',
    'username',
    'phone',
    'avatar',
    'avatar_media_id',
    'status',
    'password',
    'must_change_password',
    'activation_token',
    'activated_at',
    'timezone',
    'locale',
    'last_login_at',
    'last_login_ip',
    'last_login_user_agent',
  ];

  /**
   * @var list<string>
   */
  protected $hidden = [
    'password',
    'remember_token',
  ];

  protected static function booted(): void
  {
    static::creating(function (User $user): void {
      if (empty($user->uuid)) {
        $user->uuid = (string) Str::uuid();
      }

      $user->syncDerivedNames();
    });

    static::updating(function (User $user): void {
      if ($user->isDirty(['first_name', 'last_name', 'display_name'])) {
        $user->syncDerivedNames();
      }
    });
  }

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'email_verified_at' => 'datetime',
      'last_login_at' => 'datetime',
      'activated_at' => 'datetime',
      'must_change_password' => 'boolean',
      'password' => 'hashed',
      'status' => UserStatus::class,
    ];
  }

  public function syncDerivedNames(): void
  {
    if (empty($this->display_name)) {
      $this->display_name = trim(($this->first_name ?? '').' '.($this->last_name ?? '')) ?: null;
    }

    $this->name = $this->display_name
      ?? trim(($this->first_name ?? '').' '.($this->last_name ?? ''))
      ?: $this->email;
  }

  public function status(): UserStatus
  {
    $status = $this->status;

    return $status instanceof UserStatus ? $status : UserStatus::from((string) $status);
  }

  public function canAuthenticate(): bool
  {
    return $this->status()->canAuthenticate();
  }

  public function roles(): BelongsToMany
  {
    return $this->belongsToMany(Role::class)->withTimestamps();
  }

  public function permissions(): BelongsToMany
  {
    return $this->belongsToMany(Permission::class)->withTimestamps();
  }

  public function authenticationAuditLogs(): HasMany
  {
    return $this->hasMany(AuthenticationAuditLog::class);
  }

  public function member(): \Illuminate\Database\Eloquent\Relations\HasOne
  {
    return $this->hasOne(Member::class);
  }

  public function hasRole(string $slug): bool
  {
    if ($this->relationLoaded('roles')) {
      return $this->roles->contains('slug', $slug);
    }

    return $this->roles()->where('slug', $slug)->exists();
  }

  public function hasPermission(string $slug): bool
  {
    return app(\App\Services\Iam\AuthorizationService::class)->userHasPermission($this, $slug);
  }

  /**
   * @param  list<string>  $slugs
   */
  public function hasAnyPermission(array $slugs): bool
  {
    return app(\App\Services\Iam\AuthorizationService::class)->userHasAnyPermission($this, $slugs);
  }

  /**
   * @return list<string>
   */
  public function permissionSlugs(): array
  {
    return app(\App\Services\Iam\AuthorizationService::class)->permissionSlugsForUser($this);
  }

  public function avatarMedia(): \Illuminate\Database\Eloquent\Relations\BelongsTo
  {
    return $this->belongsTo(\App\Modules\Cms\Models\CmsMedia::class, 'avatar_media_id');
  }

  public function avatarUrl(): ?string
  {
    $media = $this->relationLoaded('avatarMedia')
      ? $this->avatarMedia
      : ($this->avatar_media_id ? $this->avatarMedia()->first() : null);

    if ($media !== null) {
      return $media->url();
    }

    if ($this->avatar === null) {
      return null;
    }

    return asset('storage/'.$this->avatar);
  }

  public function sendEmailVerificationNotification(): void
  {
    $this->notify(new \App\Notifications\VerifyEmail);
  }
}
