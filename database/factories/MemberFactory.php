<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MemberApprovalStatus;
use App\Enums\MemberStatus;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
  protected $model = Member::class;

  /**
   * @return array<string, mixed>
   */
  public function definition(): array
  {
    $firstName = fake()->firstName();
    $lastName = fake()->lastName();

    return [
      'membership_number' => 'MM-TEST-'.fake()->unique()->numerify('######'),
      'first_name' => $firstName,
      'last_name' => $lastName,
      'display_name' => $firstName.' '.$lastName,
      'gender' => fake()->randomElement(['male', 'female', 'other']),
      'date_of_birth' => fake()->date(),
      'phone' => fake()->e164PhoneNumber(),
      'email' => fake()->unique()->safeEmail(),
      'occupation' => fake()->jobTitle(),
      'organization' => fake()->company(),
      'marketplace_sector' => fake()->randomElement(['finance', 'education', 'healthcare', 'technology']),
      'skills' => fake()->words(3),
      'languages' => ['English'],
      'biography' => fake()->paragraph(),
      'status' => MemberStatus::ApplicationSubmitted,
      'approval_status' => MemberApprovalStatus::Pending,
    ];
  }

  public function active(): static
  {
    return $this->state(fn () => [
      'status' => MemberStatus::Active,
      'approval_status' => MemberApprovalStatus::Approved,
      'joined_at' => now()->toDateString(),
    ]);
  }
}
