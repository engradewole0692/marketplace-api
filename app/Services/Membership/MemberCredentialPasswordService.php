<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Contracts\ServiceContract;
use App\Models\Member;
use Illuminate\Support\Str;

/**
 * Temporary membership passwords: Surname (ucfirst), or Surname+FirstName when surname &lt; 6 chars.
 */
final class MemberCredentialPasswordService implements ServiceContract
{
  public function generate(Member $member): string
  {
    $surname = $this->normalizeNamePart((string) ($member->last_name ?? ''));
    $firstName = $this->normalizeNamePart((string) ($member->first_name ?? ''));

    if ($surname !== '' && mb_strlen($surname) >= 6) {
      return $surname;
    }

    $combined = $surname.$firstName;
    if ($combined !== '') {
      return $combined;
    }

    return 'Member'.Str::upper(Str::random(4));
  }

  private function normalizeNamePart(string $value): string
  {
    $trimmed = trim($value);
    if ($trimmed === '') {
      return '';
    }

    return Str::ucfirst(Str::lower($trimmed));
  }
}
