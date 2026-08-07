<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Models\User;
use App\Modules\Cms\Enums\CmsNotificationType;
use App\Modules\Cms\Support\HasCmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsAdminNotification extends Model
{
  use HasCmsUuid;

  protected $table = 'cms_admin_notifications';

  protected $fillable = ['uuid', 'user_id', 'type', 'title', 'message', 'data', 'read_at'];

  protected function casts(): array
  {
    return [
      'type' => CmsNotificationType::class,
      'data' => 'array',
      'read_at' => 'datetime',
    ];
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }
}
