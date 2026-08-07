<?php

declare(strict_types=1);

namespace App\Modules\Donations\Models;

use App\Models\User;
use App\Modules\Donations\Support\HasDonationUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DonationAuditLog extends Model
{
  use HasDonationUuid;

  protected $table = 'donation_audit_logs';

  protected $fillable = [
    'uuid', 'event_type', 'entity_type', 'entity_id', 'actor_id',
    'old_values', 'new_values', 'ip_address', 'user_agent',
  ];

  protected function casts(): array
  {
    return [
      'old_values' => 'array',
      'new_values' => 'array',
    ];
  }

  public function actor(): BelongsTo
  {
    return $this->belongsTo(User::class, 'actor_id');
  }
}
