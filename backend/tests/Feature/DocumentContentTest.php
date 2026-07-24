<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Models\WatchedFolder;
use App\Services\Nextcloud\ReadOnlyWebDavClient;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentContentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    #[Test]
    public function markdown_is_rendered_to_safe_html_from_the_raw_file(): void
    {
        $document = Document::factory()->create([
            'extension' => 'md',
            'mime_type' => 'text/markdown',
            'name' => 'notiz.md',
            'size' => 200,
        ]);

        // Rohdatei mit eingebettetem HTML und einem javascript:-Link — beides
        // muss unschädlich gemacht werden.
        $raw = "# Besprechung\n\nText mit <script>alert(1)</script> und "
            .'[bös](javascript:alert(2)) und [ok](https://example.de).';

        $dav = Mockery::mock(ReadOnlyWebDavClient::class);
        $dav->shouldReceive('openStream')->andReturn(Utils::streamFor($raw));
        $this->app->instance(ReadOnlyWebDavClient::class, $dav);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/documents/'.$document->uuid.'/content')
            ->assertOk()
            ->assertJsonPath('type', 'markdown');

        $html = $response->json('html');

        $this->assertStringContainsString('<h1>Besprechung</h1>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('href="https://example.de"', $html);
    }

    #[Test]
    public function an_email_returns_headers_and_body(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('texts/mail.txt', "Guten Tag,\n\nbitte den Lieferschein.\n");

        $document = Document::factory()->create([
            'extension' => 'eml',
            'mime_type' => 'message/rfc822',
            'text_key' => 'texts/mail.txt',
            'size' => 200,
            'metadata' => [
                'mail_from' => 'buchhaltung@example.de',
                'mail_to' => 'einkauf@example.de',
                'mail_subject' => 'Rückfrage',
            ],
        ]);

        $this->actingAs($this->admin())
            ->getJson('/api/documents/'.$document->uuid.'/content')
            ->assertOk()
            ->assertJsonPath('type', 'email')
            ->assertJsonPath('from', 'buchhaltung@example.de')
            ->assertJsonPath('to', 'einkauf@example.de')
            ->assertJsonPath('subject', 'Rückfrage')
            ->assertJsonPath('body', "Guten Tag,\n\nbitte den Lieferschein.\n");
    }

    #[Test]
    public function an_unsupported_format_has_no_in_app_view(): void
    {
        $document = Document::factory()->create(['extension' => 'pdf', 'mime_type' => 'application/pdf', 'size' => 200]);

        $this->actingAs($this->admin())
            ->getJson('/api/documents/'.$document->uuid.'/content')
            ->assertNotFound();
    }

    #[Test]
    public function a_user_without_the_folder_cannot_read_the_content(): void
    {
        $folder = WatchedFolder::factory()->create();
        $document = Document::factory()->for($folder, 'folder')->create([
            'nextcloud_instance_id' => $folder->nextcloud_instance_id,
            'extension' => 'md',
            'size' => 200,
        ]);

        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)
            ->getJson('/api/documents/'.$document->uuid.'/content')
            ->assertForbidden();
    }
}
