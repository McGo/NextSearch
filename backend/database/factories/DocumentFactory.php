<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\WatchedFolder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        $path = 'Dokumente/'.fake()->unique()->slug().'.pdf';

        return [
            'watched_folder_id' => WatchedFolder::factory(),
            'nextcloud_instance_id' => fn (array $attributes) => WatchedFolder::find(
                $attributes['watched_folder_id']
            )?->nextcloud_instance_id,
            'oc_file_id' => (string) fake()->unique()->numberBetween(1000, 999999),
            'remote_path' => $path,
            'path_hash' => Document::hashPath($path),
            'name' => basename($path),
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1024, 5_000_000),
            'remote_modified_at' => fake()->dateTimeBetween('-2 years'),
            'etag' => fake()->md5(),
            'state' => Document::STATE_INDEXED,
            'indexed_at' => now(),
        ];
    }
}
