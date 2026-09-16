<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum SeatClassificationScope: string
{
    case Default = 'default';
    case ParticipantCategory = 'participant_category';
    case StaffDepartment = 'staff_department';
    case StaffRole = 'staff_role';
    case Membership = 'membership';
    case Speaker = 'speaker';
}
