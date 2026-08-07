<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRegistrationSequence extends Model
{
  /**
   * @var list<string>
   */
  protected $fillable = [
    'event_id',
    'last_sequence',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'last_sequence' => 'integer',
    ];
  }

  public function event(): BelongsTo
  {
    return $this->belongsTo(Event::class);
  }
}
