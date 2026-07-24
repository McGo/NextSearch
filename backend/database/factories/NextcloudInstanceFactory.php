<?php

namespace Database\Factories;

use App\Models\NextcloudInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NextcloudInstance>
 */
class NextcloudInstanceFactory extends Factory
{
    protected $model = NextcloudInstance::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'base_url' => 'https://cloud.'.fake()->unique()->domainName(),
            'username' => fake()->userName(),
            'app_password' => fake()->password(20),
            'verify_tls' => true,
            'enabled' => true,
        ];
    }
}
