<?php

declare(strict_types=1);

namespace App\Modules\Cms\Enums;

enum CmsNotificationType: string
{
  case FormSubmission = 'form_submission';
  case MembershipApplication = 'membership_application';
}
