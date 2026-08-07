<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class MemberTag extends Model
{
  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'name',
    'slug',
    'color',
  ];

  protected static function booted(): void
  {
    static::creating(function (MemberTag $tag): void {
      if (empty($tag->uuid)) {
        $tag->uuid = (string) Str::uuid();
      }
    });
  }

  public function members(): BelongsToMany
  {
    return $this->belongsToMany(Member::class, 'member_tag_member')->withTimestamps();
  }
}
