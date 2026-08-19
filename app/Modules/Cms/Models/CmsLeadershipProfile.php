<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Support\HasCmsUuid;
use Database\Factories\CmsLeadershipProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CmsLeadershipProfile extends Model
{
  use HasCmsUuid;
  use HasFactory;
  use SoftDeletes;

  protected $table = 'cms_leadership_profiles';

  protected static function newFactory(): CmsLeadershipProfileFactory
  {
    return CmsLeadershipProfileFactory::new();
  }

  protected $fillable = [
    'uuid', 'name', 'slug', 'role', 'hierarchy_level', 'category', 'location', 'state', 'country_id', 'ministry_id',
    'member_id', 'bio', 'photo_media_id', 'email', 'phone', 'social_links', 'term_start', 'term_end',
    'visibility', 'permissions', 'is_active', 'sort_order', 'created_by', 'updated_by',
  ];

  protected function casts(): array
  {
    return ['is_active' => 'boolean', 'social_links' => 'array', 'permissions' => 'array', 'term_start' => 'date', 'term_end' => 'date'];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function country(): BelongsTo
  {
    return $this->belongsTo(CmsCountry::class, 'country_id');
  }

  public function ministry(): BelongsTo
  {
    return $this->belongsTo(CmsMinistry::class, 'ministry_id');
  }

  public function photoMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'photo_media_id');
  }
}
