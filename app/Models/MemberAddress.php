<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MemberAddress extends Model
{
  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'member_id',
    'address_type',
    'address_line_1',
    'address_line_2',
    'city',
    'state',
    'postal_code',
    'country_code',
    'is_primary',
  ];

  protected static function booted(): void
  {
    static::creating(function (MemberAddress $address): void {
      if (empty($address->uuid)) {
        $address->uuid = (string) Str::uuid();
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
