<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipNumberSequence extends Model
{
  /**
   * @var list<string>
   */
  protected $fillable = [
    'year',
    'last_sequence',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'year' => 'integer',
      'last_sequence' => 'integer',
    ];
  }
}
