<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class EventSpeaker extends Pivot
{
  protected $table = 'event_speaker';

  /**
   * @var list<string>
   */
  protected $fillable = [
    'event_id',
    'speaker_id',
    'role',
    'sort_order',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'sort_order' => 'integer',
    ];
  }

  public function event(): BelongsTo
  {
    return $this->belongsTo(Event::class);
  }

  public function speaker(): BelongsTo
  {
    return $this->belongsTo(Speaker::class);
  }
}
