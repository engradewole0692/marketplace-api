<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Models\Member;

/**
 * Resolves an event registrant to an existing enterprise Member by email or phone when
 * possible. When no Member matches, the caller falls back to storing the
 * submitted contact details on the registration's guest_* columns instead of
 * creating a new membership record.
 */
final class EventRegistrantResolver
{
  /**
   * @param  array<string, mixed>  $registrant
   * @return array{member: ?Member, guest: array{guest_name: ?string, guest_email: ?string, guest_phone: ?string}}
   */
  public function resolve(array $registrant): array
  {
    $email = isset($registrant['email']) ? trim((string) $registrant['email']) : null;
    if ($email === '') {
      $email = null;
    }

    $phone = isset($registrant['phone']) ? trim((string) $registrant['phone']) : null;
    if ($phone === '') {
      $phone = null;
    }

    $name = isset($registrant['name']) ? trim((string) $registrant['name']) : null;
    if (($name === null || $name === '') && (isset($registrant['first_name']) || isset($registrant['last_name']))) {
      $name = trim(trim((string) ($registrant['first_name'] ?? '')).' '.trim((string) ($registrant['last_name'] ?? '')));
    }
    if ($name === '') {
      $name = null;
    }

    $member = null;

    if ($email !== null) {
      $member = Member::query()->where('email', $email)->first();
    }

    if ($member === null && $phone !== null) {
      $normalizedPhone = preg_replace('/\D+/', '', $phone) ?? $phone;
      $member = Member::query()
        ->where(function ($query) use ($phone, $normalizedPhone): void {
          $query->where('phone', $phone)
            ->orWhere('alternate_phone', $phone);

          if ($normalizedPhone !== '' && $normalizedPhone !== $phone) {
            $query->orWhere('phone', $normalizedPhone)
              ->orWhere('alternate_phone', $normalizedPhone);
          }
        })
        ->first();
    }

    return [
      'member' => $member,
      'guest' => [
        'guest_name' => $name ?? ($registrant['full_name'] ?? null),
        'guest_email' => $email,
        'guest_phone' => $phone,
      ],
    ];
  }
}
