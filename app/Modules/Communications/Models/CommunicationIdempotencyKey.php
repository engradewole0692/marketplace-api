<?php

declare(strict_types=1);

namespace App\Modules\Communications\Models;

use Illuminate\Database\Eloquent\Model;

class CommunicationIdempotencyKey extends Model
{
  protected $table = 'communication_idempotency_keys';

  /** @var list<string> */
  protected $fillable = [
    'idempotency_key',
    'event_key',
  ];
}
