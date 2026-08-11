<?php

declare(strict_types=1);

namespace App\Modules\Communications\Enums;

enum CommunicationSection: string
{
  case General = 'general';
  case Membership = 'membership';
  case Learning = 'learning';
  case Counseling = 'counseling';
  case Events = 'events';
  case Donations = 'donations';
  case Contact = 'contact';
  case Prayer = 'prayer';
  case Payments = 'payments';
  case Partnership = 'partnership';
  case Newsletter = 'newsletter';

  public function label(): string
  {
    return match ($this) {
      self::General => 'General',
      self::Membership => 'Membership',
      self::Learning => 'Learning / LMS',
      self::Counseling => 'Counseling',
      self::Events => 'Events',
      self::Donations => 'Donations',
      self::Contact => 'Contact',
      self::Prayer => 'Prayer',
      self::Payments => 'Payments',
      self::Partnership => 'Partnership',
      self::Newsletter => 'Newsletter',
    };
  }
}
