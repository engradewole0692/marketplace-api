<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRegistrationQuestionAnswer extends Model
{
  use HasEventUuid;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'registration_id',
    'question_id',
    'answer_text',
    'answer_json',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'answer_json' => 'array',
    ];
  }

  public function registration(): BelongsTo
  {
    return $this->belongsTo(EventRegistration::class, 'registration_id');
  }

  public function question(): BelongsTo
  {
    return $this->belongsTo(EventRegistrationQuestion::class, 'question_id');
  }
}
