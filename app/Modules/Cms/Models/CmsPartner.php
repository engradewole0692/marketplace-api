<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Support\HasCmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CmsPartner extends Model
{
  use HasCmsUuid;
  use SoftDeletes;

  protected $table = 'cms_partners';

  protected $fillable = [
    'uuid', 'name', 'slug', 'country_id', 'tier', 'website_url', 'donation_url', 'description', 'logo_media_id',
    'is_featured', 'is_active', 'sort_order', 'created_by', 'updated_by',
  ];

  protected function casts(): array
  {
    return ['is_featured' => 'boolean', 'is_active' => 'boolean'];
  }

  public function country(): BelongsTo
  {
    return $this->belongsTo(CmsCountry::class, 'country_id');
  }

  public function logoMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'logo_media_id');
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }
}
