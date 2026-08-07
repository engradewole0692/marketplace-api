<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Support\HasCmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CmsMenu extends Model
{
  use HasCmsUuid;
  use SoftDeletes;

  protected $table = 'cms_menus';

  protected $fillable = ['uuid', 'name', 'slug', 'location', 'is_active', 'created_by', 'updated_by'];

  protected function casts(): array
  {
    return ['is_active' => 'boolean'];
  }

  public function items(): HasMany
  {
    return $this->hasMany(CmsMenuItem::class, 'menu_id')->orderBy('sort_order');
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }
}
