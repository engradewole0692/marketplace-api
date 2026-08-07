<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Models;

use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Counselling\Support\HasCounsellingUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CounsellingDocument extends Model
{
  use HasCounsellingUuid;
  use SoftDeletes;

  protected $table = 'counselling_documents';

  /** @var list<string> */
  protected $fillable = [
    'uuid',
    'case_id',
    'uploaded_by_user_id',
    'media_id',
    'title',
    'disk_path',
    'mime_type',
    'size_bytes',
    'visibility',
    'metadata',
  ];

  protected function casts(): array
  {
    return [
      'size_bytes' => 'integer',
      'metadata' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function case(): BelongsTo
  {
    return $this->belongsTo(CounsellingCase::class, 'case_id');
  }

  public function uploadedBy(): BelongsTo
  {
    return $this->belongsTo(User::class, 'uploaded_by_user_id');
  }

  public function media(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'media_id');
  }
}
