<?php

namespace Database\Factories;

use App\Models\CustomerProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerProject>
 */
class CustomerProjectFactory extends Factory
{
    protected $model = CustomerProject::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => CustomerProject::DEFAULT_NAME,
        ];
    }
}
