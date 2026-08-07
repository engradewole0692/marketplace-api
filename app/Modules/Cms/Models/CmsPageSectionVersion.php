<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Support\HasCmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsPageSectionVersion extends Model
{
  use HasCmsUuid;

  protected $table = 'cms_page_section_versions';

  protected $fillable = [
    'uuid',
    'section_id',
    'version_number',
    'status',
    'content',
    'change_summary',
    'created_by',
  ];

  protected function casts(): array
  {
    return ['content' => 'array'];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function section(): BelongsTo
  {
    return $this->belongsTo(CmsPageSection::class, 'section_id');
  }
}
