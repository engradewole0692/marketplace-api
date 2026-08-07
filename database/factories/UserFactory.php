<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
  protected static ?string $password;

  /**
   * @return array<string, mixed>
   */
  public function definition(): array
  {
    $firstName = fake()->firstName();
    $lastName = fake()->lastName();

    return [
      'first_name' => $firstName,
      'last_name' => $lastName,
      'display_name' => $firstName.' '.$lastName,
      'name' => $firstName.' '.$lastName,
      'email' => fake()->unique()->safeEmail(),
      'phone' => fake()->optional()->e164PhoneNumber(),
      'status' => UserStatus::Active,
      'email_verified_at' => now(),
      'password' => static::$password ??= Hash::make('Password123!@#'),
      'remember_token' => Str::random(10),
      'timezone' => 'UTC',
      'locale' => 'en',
    ];
  }

  public function unverified(): static
  {
    return $this->state(fn (array $attributes) => [
      'email_verified_at' => null,
    ]);
  }

  public function suspended(): static
  {
    return $this->state(fn (array $attributes) => [
      'status' => UserStatus::Suspended,
    ]);
  }
}
