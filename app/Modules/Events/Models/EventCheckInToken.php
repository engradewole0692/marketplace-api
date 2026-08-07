<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\Member;
use App\Models\User;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventCheckInToken extends Model
{
  use HasEventUuid;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'event_id',
    'registration_id',
    'member_id',
    'token_hash',
    'issued_at',
    'expires_at',
    'last_used_at',
    'revoked_at',
    'revoked_by_user_id',
    'metadata',
  ];

  /**
   * @var list<string>
   */
  protected $hidden = [
    'token_hash',
  ];

  /**
   * Plaintext token; only populated at issue/regenerate time.
   */
  public ?string $plaintextToken = null;

  public function getTokenAttribute(): ?string
  {
    return $this->plaintextToken;
  }

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'issued_at' => 'datetime',
      'expires_at' => 'datetime',
      'last_used_at' => 'datetime',
      'revoked_at' => 'datetime',
      'metadata' => 'array',
    ];
  }

  public function event(): BelongsTo
  {
    return $this->belongsTo(Event::class);
  }

  public function registration(): BelongsTo
  {
    return $this->belongsTo(EventRegistration::class, 'registration_id');
  }

  public function member(): BelongsTo
  {
    return $this->belongsTo(Member::class);
  }

  public function revoker(): BelongsTo
  {
    return $this->belongsTo(User::class, 'revoked_by_user_id');
  }
}
