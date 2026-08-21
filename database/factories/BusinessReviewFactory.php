<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\BusinessReview\Models\BusinessReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessReview>
 */
class BusinessReviewFactory extends Factory
{
    protected $model = BusinessReview::class;

    public function definition(): array
    {
        return [
            'full_name' => $this->faker->name(),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'business_name' => $this->faker->company(),
            'business_location' => $this->faker->city().', '.$this->faker->country(),
            'country' => $this->faker->country(),
            'state_province' => $this->faker->state(),
            'business_industry' => $this->faker->randomElement(['Technology', 'Finance', 'Healthcare', 'Agriculture', 'Education']),
            'business_description' => $this->faker->paragraph(),
            'business_stage' => $this->faker->randomElement(['Business Idea', 'Startup', 'Existing Business', 'Growing Business', 'Expanding Business']),
            'years_in_operation' => $this->faker->numberBetween(0, 20),
            'advice_areas' => $this->faker->sentence(),
            'main_challenges' => $this->faker->sentence(),
            'business_goals' => $this->faker->sentence(),
            'preferred_contact' => 'email',
            'status' => 'new',
        ];
    }
}
