<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Support\HasCmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CmsSeo extends Model
{
  use HasCmsUuid;
  use SoftDeletes;

  protected $table = 'cms_seo';

  protected $fillable = [
    'uuid', 'entity_type', 'entity_id', 'path', 'meta_title', 'meta_description', 'meta_keywords',
    'canonical_url', 'og_title', 'og_description', 'og_image_id', 'twitter_card',
    'json_ld', 'no_index', 'robots', 'created_by', 'updated_by',
  ];

  protected function casts(): array
  {
    return ['json_ld' => 'array', 'no_index' => 'boolean'];
  }

  public function ogImage(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'og_image_id');
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }
}
