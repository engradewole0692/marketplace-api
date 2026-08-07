<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventCertificateTemplate extends Model
{
  use HasEventUuid;
  use SoftDeletes;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'event_id',
    'name',
    'slug',
    'html_body',
    'background_media_id',
    'is_active',
    'sort_order',
    'created_by_user_id',
    'updated_by_user_id',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'is_active' => 'boolean',
      'sort_order' => 'integer',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function event(): BelongsTo
  {
    return $this->belongsTo(Event::class);
  }

  public function backgroundMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'background_media_id');
  }

  public function creator(): BelongsTo
  {
    return $this->belongsTo(User::class, 'created_by_user_id');
  }
}
