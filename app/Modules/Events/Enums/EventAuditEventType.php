<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum EventAuditEventType: string
{
  case Created = 'created';
  case Updated = 'updated';
  case Deleted = 'deleted';
  case Restored = 'restored';
  case Published = 'published';
  case StatusChanged = 'status_changed';
  case MediaAttached = 'media_attached';
  case MediaDetached = 'media_detached';
  case SessionChanged = 'session_changed';
  case SpeakerChanged = 'speaker_changed';
  case RegistrationQuestionChanged = 'registration_question_changed';
}
