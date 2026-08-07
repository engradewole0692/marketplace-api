<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionOption extends Model
{
  use HasLmsUuid;

  protected $table = 'lms_question_options';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'question_id', 'label', 'body', 'match_key', 'is_correct', 'sort_order',
  ];

  protected function casts(): array
  {
    return [
      'is_correct' => 'boolean',
      'sort_order' => 'integer',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function question(): BelongsTo
  {
    return $this->belongsTo(Question::class, 'question_id');
  }
}
