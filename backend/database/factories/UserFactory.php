<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'email' => $this->faker->unique()->safeEmail(),
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => $this->faker->userName(),
            'is_verified' => 0,
            'verification_status' => 0,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (): array => [
            'is_verified' => 1,
            'verification_status' => 2,
        ]);
    }
}
