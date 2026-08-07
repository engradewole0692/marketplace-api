<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CounsellorAvailability extends Model
{
  protected $table = 'counselling_counsellor_availability';

  /** @var list<string> */
  protected $fillable = [
    'counsellor_id',
    'weekday',
    'starts_at',
    'ends_at',
    'timezone',
    'is_active',
  ];

  protected function casts(): array
  {
    return [
      'weekday' => 'integer',
      'is_active' => 'boolean',
    ];
  }

  public function counsellor(): BelongsTo
  {
    return $this->belongsTo(Counsellor::class, 'counsellor_id');
  }
}
