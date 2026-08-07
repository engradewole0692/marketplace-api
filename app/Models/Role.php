<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Role extends Model
{
  use SoftDeletes;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'name',
    'slug',
    'guard_name',
    'description',
    'is_system',
  ];

  protected static function booted(): void
  {
    static::creating(function (Role $role): void {
      if (empty($role->uuid)) {
        $role->uuid = (string) Str::uuid();
      }
    });
  }

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'is_system' => 'boolean',
    ];
  }

  public function users(): BelongsToMany
  {
    return $this->belongsToMany(User::class)->withTimestamps();
  }

  public function permissions(): BelongsToMany
  {
    return $this->belongsToMany(Permission::class)->withTimestamps();
  }
}
