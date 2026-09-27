<?php

namespace Database\Factories;

use App\Models\Contracts\UserInterface;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            UserInterface::USERNAME => fake()->unique()->userName(),
            // The model's hashed cast turns this into a real hash on assignment.
            UserInterface::PASSWORD_HASH => 'password',
            UserInterface::ROLE => UserInterface::ROLE_ADMIN,
        ];
    }
}
