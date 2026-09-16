<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

final class OccupationCatalog
{
    /**
     * @return list<string>
     */
    public static function options(): array
    {
        return [
            'Pastor',
            'Minister / Clergy',
            'Evangelist',
            'Missionary',
            'Church Worker',
            'Teacher / Educator',
            'Student',
            'Civil Servant',
            'Business Owner',
            'Medical Professional',
            'Engineer',
            'Lawyer',
            'Accountant',
            'IT / Technology',
            'Farmer',
            'Homemaker',
            'Retired',
            'Other',
        ];
    }
}
