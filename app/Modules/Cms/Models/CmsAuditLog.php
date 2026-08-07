<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Enums\CmsAuditEventType;
use App\Modules\Cms\Support\HasCmsUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsAuditLog extends Model
{
  use HasCmsUuid;

  protected $table = 'cms_audit_logs';

  public $timestamps = false;

  protected $fillable = [
    'uuid', 'event_type', 'entity_type', 'entity_id', 'actor_id',
    'old_values', 'new_values', 'ip_address', 'user_agent', 'created_at',
  ];

  protected function casts(): array
  {
    return [
      'event_type' => CmsAuditEventType::class,
      'old_values' => 'array',
      'new_values' => 'array',
      'created_at' => 'datetime',
    ];
  }

  public function actor(): BelongsTo
  {
    return $this->belongsTo(User::class, 'actor_id');
  }
}
