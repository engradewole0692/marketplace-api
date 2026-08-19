<?php

declare(strict_types=1);

namespace App\Modules\Communications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlatformAnnouncement extends Model
{
  use SoftDeletes;

  protected $table = 'platform_announcements';

  protected $fillable = [
    'uuid', 'title', 'content', 'image_path', 'status',
    'target_audience', 'show_on_public', 'send_email', 'send_notification',
    'target_countries', 'target_regions', 'target_ministries', 'target_roles',
    'publish_at', 'expires_at', 'published_by', 'published_at', 'created_by',
  ];

  protected function casts(): array
  {
    return [
      'show_on_public' => 'boolean',
      'send_email' => 'boolean',
      'send_notification' => 'boolean',
      'target_countries' => 'array',
      'target_regions' => 'array',
      'target_ministries' => 'array',
      'target_roles' => 'array',
      'publish_at' => 'datetime',
      'expires_at' => 'datetime',
      'published_at' => 'datetime',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function creator(): BelongsTo
  {
    return $this->belongsTo(\App\Models\User::class, 'created_by');
  }

  public function publisher(): BelongsTo
  {
    return $this->belongsTo(\App\Models\User::class, 'published_by');
  }

  public function scopeActive($query): mixed
  {
    return $query->where('status', 'published')
      ->where(function ($q): void {
        $q->whereNull('publish_at')->orWhere('publish_at', '<=', now());
      })
      ->where(function ($q): void {
        $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
      });
  }
}
