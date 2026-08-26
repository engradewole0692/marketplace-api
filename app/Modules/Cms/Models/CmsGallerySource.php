<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Support\HasCmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CmsGallerySource extends Model
{
  use HasCmsUuid;
  use SoftDeletes;

  protected $table = 'cms_gallery_sources';

  protected $fillable = [
    'uuid',
    'name',
    'provider',
    'source_url',
    'remote_id',
    'access_status',
    'is_enabled',
    'last_synced_at',
    'imported_count',
    'skipped_count',
    'duplicate_count',
    'failed_count',
    'last_error',
    'metadata',
    'created_by',
    'updated_by',
  ];

  protected function casts(): array
  {
    return [
      'is_enabled' => 'boolean',
      'last_synced_at' => 'datetime',
      'imported_count' => 'integer',
      'skipped_count' => 'integer',
      'duplicate_count' => 'integer',
      'failed_count' => 'integer',
      'metadata' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }
}
