<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    protected $model = Person::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();
        $phone = fake()->e164PhoneNumber();

        return [
            'uuid' => (string) Str::uuid(),
            'person_no' => 'PERSON-TMP-'.Str::lower(Str::random(8)),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => $firstName.' '.$lastName,
            'email' => fake()->unique()->safeEmail(),
            'phone' => $phone,
            'phone_digits' => Person::digits($phone),
            'region' => fake()->state(),
            'city' => fake()->city(),
            'organization' => fake()->company(),
        ];
    }
}
