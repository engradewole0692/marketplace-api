<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Support\HasCmsUuid;
use Illuminate\Database\Eloquent\Model;

class CmsSetting extends Model
{
  use HasCmsUuid;

  protected $table = 'cms_settings';

  protected $fillable = ['uuid', 'group', 'key', 'value', 'type', 'is_public', 'updated_by'];

  protected function casts(): array
  {
    return ['value' => 'array', 'is_public' => 'boolean'];
  }
}
