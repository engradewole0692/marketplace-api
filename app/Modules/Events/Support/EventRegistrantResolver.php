<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Models\Member;

/**
 * Resolves an event registrant to an existing enterprise Member by email when
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
    $member = null;

    if (! empty($email)) {
      $member = Member::query()->where('email', $email)->first();
    }

    return [
      'member' => $member,
      'guest' => [
        'guest_name' => $registrant['name'] ?? $registrant['full_name'] ?? null,
        'guest_email' => $email,
        'guest_phone' => $registrant['phone'] ?? null,
      ],
    ];
  }
}
