<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Cms\Models\CmsLeadershipProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CmsLeadershipProfile>
 */
class CmsLeadershipProfileFactory extends Factory
{
    protected $model = CmsLeadershipProfile::class;

    public function definition(): array
    {
        $name = $this->faker->name();

        return [
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.uniqid(),
            'role' => $this->faker->jobTitle(),
            'category' => 'team',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
