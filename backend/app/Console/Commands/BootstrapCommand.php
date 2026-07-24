<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Search\SearchIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Läuft bei jedem Start des App-Containers. Alles hier ist idempotent.
 */
class BootstrapCommand extends Command
{
    protected $signature = 'nextsearch:bootstrap';

    protected $description = 'Admin-Zugang, Objektspeicher und Suchindex einrichten';

    public function handle(SearchIndex $index): int
    {
        $this->ensureAdmin();
        $this->ensureBucket();
        $this->ensureIndex($index);

        return self::SUCCESS;
    }

    private function ensureAdmin(): void
    {
        $email = config('nextsearch.admin.email');
        $password = config('nextsearch.admin.password');

        if (blank($email) || blank($password)) {
            $this->warn('ADMIN_EMAIL oder ADMIN_PASSWORD fehlt — kein Admin angelegt.');

            return;
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            // Ein bestehendes Konto wird nicht überschrieben. Wer das Passwort
            // vergessen hat, setzt es in der Oberfläche zurück.
            if (! $existing->isAdmin()) {
                $existing->forceFill(['role' => User::ROLE_ADMIN])->save();
                $this->info(sprintf('Konto %s zum Administrator gemacht.', $email));
            }

            return;
        }

        User::query()->create([
            'name' => config('nextsearch.admin.name'),
            'email' => $email,
            'password' => $password,
            'role' => User::ROLE_ADMIN,
        ]);

        $this->info(sprintf('Administrator %s angelegt.', $email));
    }

    private function ensureBucket(): void
    {
        $disk = (string) config('nextsearch.preview.disk');

        if ($disk !== 's3') {
            return;
        }

        try {
            $this->createBucketIfMissing($disk);

            // Ein Schreibvorgang sagt mehr als eine Existenzprüfung.
            Storage::disk($disk)->put('.nextsearch-ready', (string) now());
            $this->info('Objektspeicher erreichbar.');
        } catch (Throwable $e) {
            $this->warn('Objektspeicher nicht erreichbar: '.$e->getMessage());
            $this->warn('Vorschaubilder werden erst funktionieren, wenn der Bucket existiert.');
        }
    }

    /**
     * Das mitgelieferte MinIO startet ohne Bucket. Bei einem echten S3-Anbieter
     * fehlt meist die Berechtigung dafür — dann wird die Meldung geschluckt und
     * der Bucket muss von Hand existieren.
     */
    private function createBucketIfMissing(string $disk): void
    {
        $adapter = Storage::disk($disk);

        if (! method_exists($adapter, 'getClient')) {
            return;
        }

        $client = $adapter->getClient();
        $bucket = (string) config('filesystems.disks.'.$disk.'.bucket');

        if ($client->doesBucketExist($bucket)) {
            return;
        }

        try {
            $client->createBucket(['Bucket' => $bucket]);
            $this->info(sprintf('Bucket "%s" angelegt.', $bucket));
        } catch (Throwable $e) {
            $this->warn(sprintf('Bucket "%s" konnte nicht angelegt werden: %s', $bucket, $e->getMessage()));
        }
    }

    private function ensureIndex(SearchIndex $index): void
    {
        try {
            $index->configure();
            $this->info(sprintf('Suchindex "%s" eingerichtet.', $index->name()));
        } catch (Throwable $e) {
            $this->warn('Suchindex nicht erreichbar: '.$e->getMessage());
        }
    }
}
