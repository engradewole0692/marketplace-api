<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MemberContact extends Model
{
  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'member_id',
    'contact_type',
    'name',
    'relationship',
    'phone',
    'email',
    'is_primary',
  ];

  protected static function booted(): void
  {
    static::creating(function (MemberContact $contact): void {
      if (empty($contact->uuid)) {
        $contact->uuid = (string) Str::uuid();
      }
    });
  }

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'is_primary' => 'boolean',
    ];
  }

  public function member(): BelongsTo
  {
    return $this->belongsTo(Member::class);
  }
}
