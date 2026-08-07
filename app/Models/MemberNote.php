<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MemberNote extends Model
{
  use SoftDeletes;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'member_id',
    'author_id',
    'body',
    'is_private',
  ];

  protected static function booted(): void
  {
    static::creating(function (MemberNote $note): void {
      if (empty($note->uuid)) {
        $note->uuid = (string) Str::uuid();
      }
    });
  }

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'is_private' => 'boolean',
    ];
  }

  public function member(): BelongsTo
  {
    return $this->belongsTo(Member::class);
  }

  public function author(): BelongsTo
  {
    return $this->belongsTo(User::class, 'author_id');
  }
}
