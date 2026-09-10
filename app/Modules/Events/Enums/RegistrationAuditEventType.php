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
  case IdentityResolved = 'identity_resolved';
  case ServiceUpdated = 'service_updated';
  case ServiceConfirmed = 'service_confirmed';
  case ServiceCancelled = 'service_cancelled';
  case PairingRequested = 'pairing_requested';
  case PairingConfirmed = 'pairing_confirmed';
  case PairingDeclined = 'pairing_declined';
  case AccommodationAllocated = 'accommodation_allocated';
  case AccommodationConfirmed = 'accommodation_confirmed';
  case TransportConfirmed = 'transport_confirmed';
  case TravelStatusChanged = 'travel_status_changed';
  case PaymentVerified = 'payment_verified';
}
