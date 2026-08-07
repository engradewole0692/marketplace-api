<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Support\HasCmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsPageVersion extends Model
{
  use HasCmsUuid;

  protected $table = 'cms_page_versions';

  protected $fillable = ['uuid', 'page_id', 'version_number', 'title', 'status', 'snapshot', 'change_summary', 'created_by'];

  protected function casts(): array
  {
    return ['snapshot' => 'array'];
  }

  public function page(): BelongsTo
  {
    return $this->belongsTo(CmsPage::class, 'page_id');
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }
}
