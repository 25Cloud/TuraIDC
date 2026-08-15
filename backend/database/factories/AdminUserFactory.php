<?php

namespace Database\Factories;

use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;

class AdminUserFactory extends Factory
{
    protected $model = AdminUser::class;

    public function definition(): array
    {
        return [
            'username' => 'admin-'.$this->faker->unique()->userName(),
            'password' => 'Temp@123456',
            'nickname' => '管理员',
            'email' => $this->faker->unique()->safeEmail(),
            'status' => 1,
        ];
    }
}
