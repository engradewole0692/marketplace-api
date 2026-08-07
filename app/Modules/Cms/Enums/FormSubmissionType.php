<?php

declare(strict_types=1);

namespace App\Modules\Cms\Enums;

enum FormSubmissionType: string
{
  case Contact = 'contact';
  case Counseling = 'counseling';
  case Newsletter = 'newsletter';
  case Partnership = 'partnership';
  case DonationInterest = 'donation_interest';
  case Prayer = 'prayer';
  case Volunteer = 'volunteer';
  case MembershipApplication = 'membership_application';
  case Testimony = 'testimony';
}
