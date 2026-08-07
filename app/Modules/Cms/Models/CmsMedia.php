<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Support\HasCmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class CmsMedia extends Model
{
  use HasCmsUuid;
  use SoftDeletes;

  protected $table = 'cms_media';

  protected $fillable = [
    'uuid',
    'folder_id',
    'name',
    'file_name',
    'disk',
    'path',
    'content_hash',
    'mime_type',
    'size',
    'width',
    'height',
    'alt_text',
    'title',
    'thumbnail_path',
    'metadata',
    'tags',
    'credits',
    'copyright',
    'focal_x',
    'focal_y',
    'variants',
    'is_optimized',
    'created_by',
    'updated_by',
  ];

  protected function casts(): array
  {
    return [
      'metadata' => 'array',
      'tags' => 'array',
      'variants' => 'array',
      'size' => 'integer',
      'width' => 'integer',
      'height' => 'integer',
      'focal_x' => 'float',
      'focal_y' => 'float',
      'is_optimized' => 'boolean',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function folder(): BelongsTo
  {
    return $this->belongsTo(CmsMediaFolder::class, 'folder_id');
  }

  public function url(): string
  {
    return Storage::disk($this->disk)->url($this->path);
  }

  public function thumbnailUrl(): ?string
  {
    return $this->thumbnail_path
      ? Storage::disk($this->disk)->url($this->thumbnail_path)
      : null;
  }

  /**
   * @return list<array{url: string, width: int, format: string}>
   */
  public function responsiveUrls(): array
  {
    $disk = Storage::disk($this->disk);
    $out = [];
    foreach (($this->variants['responsive'] ?? []) as $variant) {
      if (! is_array($variant) || empty($variant['path'])) {
        continue;
      }
      $out[] = [
        'url' => $disk->url($variant['path']),
        'width' => (int) ($variant['width'] ?? 0),
        'format' => (string) ($variant['format'] ?? 'jpeg'),
      ];
    }

    return $out;
  }

  /**
   * @return list<array{url: string, width: int, format: string}>
   */
  public function webpUrls(): array
  {
    $disk = Storage::disk($this->disk);
    $out = [];
    foreach (($this->variants['webp'] ?? []) as $variant) {
      if (! is_array($variant) || empty($variant['path'])) {
        continue;
      }
      $out[] = [
        'url' => $disk->url($variant['path']),
        'width' => (int) ($variant['width'] ?? 0),
        'format' => 'webp',
      ];
    }

    return $out;
  }
}
