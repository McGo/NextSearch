<?php

namespace Tests\Feature;

use App\Jobs\RelabelFolderDocumentsJob;
use App\Models\User;
use App\Models\WatchedFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FolderRenameTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function renaming_a_folder_relabels_its_documents_in_the_index(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $folder = WatchedFolder::factory()->create(['label' => 'Archiv']);

        $this->actingAs($admin)
            ->putJson("/api/admin/folders/{$folder->uuid}", ['label' => 'Familienarchiv'])
            ->assertOk()
            ->assertJsonPath('folder.label', 'Familienarchiv');

        Queue::assertPushed(
            RelabelFolderDocumentsJob::class,
            fn (RelabelFolderDocumentsJob $job) => $job->folderId === $folder->id
                && $job->label === 'Familienarchiv',
        );
    }

    #[Test]
    public function changing_other_fields_does_not_trigger_a_relabel(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $folder = WatchedFolder::factory()->create(['label' => 'Archiv', 'interval_minutes' => 15]);

        $this->actingAs($admin)
            ->putJson("/api/admin/folders/{$folder->uuid}", ['interval_minutes' => 60])
            ->assertOk();

        Queue::assertNotPushed(RelabelFolderDocumentsJob::class);
    }

    #[Test]
    public function submitting_the_same_label_does_not_trigger_a_relabel(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $folder = WatchedFolder::factory()->create(['label' => 'Archiv']);

        $this->actingAs($admin)
            ->putJson("/api/admin/folders/{$folder->uuid}", ['label' => 'Archiv'])
            ->assertOk();

        Queue::assertNotPushed(RelabelFolderDocumentsJob::class);
    }
}
