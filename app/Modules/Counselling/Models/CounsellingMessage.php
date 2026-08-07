<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Models;

use App\Models\User;
use App\Modules\Counselling\Support\HasCounsellingUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CounsellingMessage extends Model
{
  use HasCounsellingUuid;
  use SoftDeletes;

  protected $table = 'counselling_messages';

  /** @var list<string> */
  protected $fillable = [
    'uuid',
    'case_id',
    'sender_user_id',
    'sender_role',
    'body',
    'attachments',
    'read_at',
  ];

  protected function casts(): array
  {
    return [
      'attachments' => 'array',
      'read_at' => 'datetime',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function case(): BelongsTo
  {
    return $this->belongsTo(CounsellingCase::class, 'case_id');
  }

  public function sender(): BelongsTo
  {
    return $this->belongsTo(User::class, 'sender_user_id');
  }
}
