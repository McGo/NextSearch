<?php

namespace Database\Factories;

use App\Models\NextcloudInstance;
use App\Models\WatchedFolder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WatchedFolder>
 */
class WatchedFolderFactory extends Factory
{
    protected $model = WatchedFolder::class;

    public function definition(): array
    {
        return [
            'nextcloud_instance_id' => NextcloudInstance::factory(),
            'label' => fake()->words(2, true),
            'remote_path' => 'Dokumente/'.fake()->unique()->word(),
            'oc_file_id' => (string) fake()->unique()->numberBetween(1000, 99999),
            'enabled' => true,
            'interval_minutes' => 15,
        ];
    }
}
