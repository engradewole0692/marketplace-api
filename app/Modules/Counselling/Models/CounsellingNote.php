<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Models;

use App\Models\User;
use App\Modules\Counselling\Enums\NoteVisibility;
use App\Modules\Counselling\Support\HasCounsellingUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CounsellingNote extends Model
{
  use HasCounsellingUuid;
  use SoftDeletes;

  protected $table = 'counselling_notes';

  /** @var list<string> */
  protected $fillable = [
    'uuid',
    'case_id',
    'author_user_id',
    'visibility',
    'body',
    'metadata',
  ];

  protected function casts(): array
  {
    return [
      'visibility' => NoteVisibility::class,
      'metadata' => 'array',
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

  public function author(): BelongsTo
  {
    return $this->belongsTo(User::class, 'author_user_id');
  }
}
