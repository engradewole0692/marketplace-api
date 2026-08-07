<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum TimelineEventType: string
{
  case Created = 'created';
  case Updated = 'updated';
  case StatusChanged = 'status_changed';
  case RegistrationSubmitted = 'registration_submitted';
  case RegistrationApproved = 'registration_approved';
  case RegistrationCancelled = 'registration_cancelled';
  case CheckInRecorded = 'check_in_recorded';
  case AttendanceRecorded = 'attendance_recorded';
  case CertificateIssued = 'certificate_issued';
}
