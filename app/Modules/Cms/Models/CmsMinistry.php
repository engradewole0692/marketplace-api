<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Support\HasCmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CmsMinistry extends Model
{
  use HasCmsUuid;
  use SoftDeletes;

  protected $table = 'cms_ministries';

  protected $fillable = [
    'uuid', 'name', 'slug', 'icon', 'color', 'tagline', 'summary', 'about', 'mission', 'vision',
    'purposes', 'programs', 'content', 'hero_media_id', 'logo_media_id', 'banner_media_id', 'cover_media_id',
    'visibility', 'operational_status', 'leader_member_id', 'assistant_leader_member_id',
    'whatsapp_link', 'telegram_link', 'signal_link', 'country_availability',
    'is_active', 'sort_order', 'created_by', 'updated_by',
  ];

  protected function casts(): array
  {
    return [
      'purposes' => 'array',
      'programs' => 'array',
      'content' => 'array',
      'country_availability' => 'array',
      'is_active' => 'boolean',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function leaders(): HasMany
  {
    return $this->hasMany(CmsLeadershipProfile::class, 'ministry_id');
  }

  public function heroMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'hero_media_id');
  }

  public function logoMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'logo_media_id');
  }

  public function bannerMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'banner_media_id');
  }

  public function coverMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'cover_media_id');
  }

  public function leaderMember(): BelongsTo
  {
    return $this->belongsTo(\App\Models\Member::class, 'leader_member_id');
  }

  public function assistantLeaderMember(): BelongsTo
  {
    return $this->belongsTo(\App\Models\Member::class, 'assistant_leader_member_id');
  }
}
