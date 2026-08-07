<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRegistrationFieldSetting extends Model
{
  use HasEventUuid;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'event_id',
    'field_key',
    'label',
    'is_enabled',
    'is_required',
    'sort_order',
    'metadata',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'is_enabled' => 'boolean',
      'is_required' => 'boolean',
      'sort_order' => 'integer',
      'metadata' => 'array',
    ];
  }

  public function event(): BelongsTo
  {
    return $this->belongsTo(Event::class);
  }
}
