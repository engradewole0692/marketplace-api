<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum RegistrationAuditEventType: string
{
  case RegistrationCreated = 'registration_created';
  case RegistrationUpdated = 'registration_updated';
  case RegistrationDeleted = 'registration_deleted';
  case StatusChanged = 'status_changed';
  case QuestionAnswered = 'question_answered';
  case CheckInRecorded = 'check_in_recorded';
  case CheckOutRecorded = 'check_out_recorded';
  case CertificateIssued = 'certificate_issued';
  case NotificationQueued = 'notification_queued';
}
