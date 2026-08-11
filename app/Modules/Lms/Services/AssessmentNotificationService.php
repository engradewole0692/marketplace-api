<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Modules\Communications\Services\CommunicationLmsBridge;
use App\Modules\Lms\Models\AssessmentAttempt;

final class AssessmentNotificationService implements ServiceContract
{
  public function __construct(
    private readonly CommunicationLmsBridge $communications,
  ) {}

  public function notifySubmitted(AssessmentAttempt $attempt): void
  {
    $this->communications->notifyAssessmentSubmitted($attempt);
  }

  public function notifyResult(AssessmentAttempt $attempt): void
  {
    $this->communications->notifyAssessmentResult($attempt);
  }
}
