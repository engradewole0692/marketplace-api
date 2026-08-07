<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Support\HasCmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CmsMediaFolder extends Model
{
  use HasCmsUuid;
  use SoftDeletes;

  protected $table = 'cms_media_folders';

  protected $fillable = ['uuid', 'parent_id', 'name', 'slug', 'sort_order', 'created_by', 'updated_by'];

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function parent(): BelongsTo
  {
    return $this->belongsTo(self::class, 'parent_id');
  }

  public function children(): HasMany
  {
    return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
  }

  public function media(): HasMany
  {
    return $this->hasMany(CmsMedia::class, 'folder_id');
  }
}
