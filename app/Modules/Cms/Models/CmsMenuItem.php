<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Support\HasCmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CmsMenuItem extends Model
{
  use HasCmsUuid;
  use SoftDeletes;

  protected $table = 'cms_menu_items';

  protected $fillable = [
    'uuid', 'menu_id', 'parent_id', 'label', 'url', 'route_name', 'icon',
    'open_in_new_tab', 'is_active', 'sort_order',
  ];

  protected function casts(): array
  {
    return ['open_in_new_tab' => 'boolean', 'is_active' => 'boolean'];
  }

  public function menu(): BelongsTo
  {
    return $this->belongsTo(CmsMenu::class, 'menu_id');
  }

  public function children(): HasMany
  {
    return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
  }
}
