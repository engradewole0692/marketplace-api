<?php

declare(strict_types=1);

namespace App\Enums;

enum MemberDocumentType: string
{
  case Photo = 'photo';
  case IdCard = 'id_card';
  case Cv = 'cv';
  case Certificate = 'certificate';
  case Supporting = 'supporting';

  public function label(): string
  {
    return match ($this) {
      self::Photo => 'Photo',
      self::IdCard => 'ID Card',
      self::Cv => 'CV',
      self::Certificate => 'Certificate',
      self::Supporting => 'Supporting Document',
    };
  }
}
