<?php

declare(strict_types=1);

namespace App\Modules\Communications\Models;

use App\Models\User;
use App\Modules\Communications\Support\HasCommunicationUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationRoute extends Model
{
  use HasCommunicationUuid;

  protected $table = 'communication_routes';

  /** @var list<string> */
  protected $fillable = [
    'uuid',
    'section',
    'event_key',
    'label',
    'recipient_role',
    'recipient_type',
    'email',
    'user_id',
    'sort_order',
    'include_section_fallback',
    'include_ministry_fallback',
    'is_active',
    'metadata',
  ];

  protected function casts(): array
  {
    return [
      'include_section_fallback' => 'boolean',
      'include_ministry_fallback' => 'boolean',
      'is_active' => 'boolean',
      'metadata' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }
}
