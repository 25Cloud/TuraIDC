<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        $name = 'role-'.$this->faker->unique()->slug(3);

        return [
            'name' => $name,
            'label' => $this->faker->words(2, true),
            'permissions' => [],
        ];
    }
}
