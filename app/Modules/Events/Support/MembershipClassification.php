<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Enums\MemberApprovalStatus;
use App\Models\Member;
use App\Models\Person;

/**
 * Authoritative member vs visitor classification from membership records.
 * Reports default to CURRENT status; registration metadata may store a snapshot.
 */
final class MembershipClassification
{
    /**
     * @return array{
     *   type: string,
     *   label: string,
     *   member_id: ?string,
     *   membership_number: ?string,
     *   membership_status: ?string,
     *   approval_status: ?string,
     *   workspace: string
     * }
     */
    public static function forPerson(?Person $person): array
    {
        return self::forMember($person?->member);
    }

    /**
     * @return array{
     *   type: string,
     *   label: string,
     *   member_id: ?string,
     *   membership_number: ?string,
     *   membership_status: ?string,
     *   approval_status: ?string,
     *   workspace: string
     * }
     */
    public static function forMember(?Member $member): array
    {
        if ($member !== null && self::isApprovedMember($member)) {
            $status = $member->status instanceof \BackedEnum ? $member->status->value : (string) $member->status;
            $approval = $member->approval_status instanceof \BackedEnum
                ? $member->approval_status->value
                : (string) $member->approval_status;

            return [
                'type' => 'approved_member',
                'label' => 'Approved Member',
                'member_id' => $member->uuid,
                'membership_number' => $member->membership_number,
                'membership_status' => $status,
                'approval_status' => $approval,
                'workspace' => 'member',
            ];
        }

        return [
            'type' => 'visitor',
            'label' => 'Visitor',
            'member_id' => $member?->uuid,
            'membership_number' => $member?->membership_number,
            'membership_status' => $member?->status instanceof \BackedEnum
                ? $member->status->value
                : ($member?->status !== null ? (string) $member->status : null),
            'approval_status' => $member?->approval_status instanceof \BackedEnum
                ? $member->approval_status->value
                : ($member?->approval_status !== null ? (string) $member->approval_status : null),
            'workspace' => 'visitor',
        ];
    }

    public static function isApprovedMember(Member $member): bool
    {
        if (! $member->isActiveMember()) {
            return false;
        }

        $approval = $member->approval_status instanceof MemberApprovalStatus
            ? $member->approval_status
            : MemberApprovalStatus::tryFrom((string) $member->approval_status);

        return $approval === MemberApprovalStatus::Approved;
    }
}
