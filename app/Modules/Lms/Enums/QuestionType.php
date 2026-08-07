<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum QuestionType: string
{
  case MultipleChoice = 'multiple_choice';
  case Checkbox = 'checkbox';
  case TrueFalse = 'true_false';
  case Essay = 'essay';
  case Matching = 'matching';
  case Ordering = 'ordering';
  case FillBlank = 'fill_blank';
  case ShortAnswer = 'short_answer';

  public function label(): string
  {
    return str_replace('_', ' ', ucfirst($this->value));
  }
}
