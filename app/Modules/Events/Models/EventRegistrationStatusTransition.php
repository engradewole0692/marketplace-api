<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\User;
use App\Modules\Events\Enums\RegistrationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRegistrationStatusTransition extends Model
{
  /**
   * @var list<string>
   */
  protected $fillable = [
    'registration_id',
    'from_status',
    'to_status',
    'actor_id',
    'reason',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'from_status' => RegistrationStatus::class,
      'to_status' => RegistrationStatus::class,
    ];
  }

  public function registration(): BelongsTo
  {
    return $this->belongsTo(EventRegistration::class, 'registration_id');
  }

  public function actor(): BelongsTo
  {
    return $this->belongsTo(User::class, 'actor_id');
  }
}
