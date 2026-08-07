<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Lms\Enums\QuestionType;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Question extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_questions';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'prompt', 'stem', 'question_type', 'default_points', 'correct_payload',
    'metadata', 'difficulty', 'status', 'explanation',
    'created_by_user_id', 'updated_by_user_id',
  ];

  protected function casts(): array
  {
    return [
      'question_type' => QuestionType::class,
      'default_points' => 'decimal:2',
      'correct_payload' => 'array',
      'metadata' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function options(): HasMany
  {
    return $this->hasMany(QuestionOption::class, 'question_id')->orderBy('sort_order');
  }

  public function createdBy(): BelongsTo
  {
    return $this->belongsTo(User::class, 'created_by_user_id');
  }
}
