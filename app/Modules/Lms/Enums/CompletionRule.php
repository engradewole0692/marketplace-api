<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum CompletionRule: string
{
  case AllMandatoryLessons = 'all_mandatory_lessons';
  case AllLessons = 'all_lessons';
  case PercentThreshold = 'percent_threshold';
  case AssessmentPass = 'assessment_pass';
  case AssignmentPass = 'assignment_pass';
  case AssessmentAndAssignment = 'assessment_and_assignment';

  public function label(): string
  {
    return str_replace('_', ' ', ucfirst($this->value));
  }
}
