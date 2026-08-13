<?php

namespace App\Console\Commands;

use App\Models\Tag;
use App\Models\User;
use App\Services\Search\SearchIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs on every start of the app container. Everything here is idempotent.
 */
class BootstrapCommand extends Command
{
    protected $signature = 'nextsearch:bootstrap';

    protected $description = 'Set up the admin account, object storage and search index';

    public function handle(SearchIndex $index): int
    {
        $this->ensureAdmin();
        $this->ensureSystemTags();
        $this->ensureBucket();
        $this->ensureIndex($index);

        return self::SUCCESS;
    }

    /**
     * The tags that come with the installation. "Neu" is created once; name,
     * colour, duration and the on/off switch afterwards belong to whoever
     * administers the instance, so an existing row stays untouched.
     */
    private function ensureSystemTags(): void
    {
        if (Tag::query()->where('slug', Tag::SLUG_NEW)->whereNull('owner_id')->exists()) {
            return;
        }

        $tag = new Tag;
        $tag->forceFill([
            'slug' => Tag::SLUG_NEW,
            // Null on purpose: the interface translates system tags, so the
            // label follows the language instead of the seed.
            'name' => null,
            'color' => 'primary',
            'scope' => Tag::SCOPE_GLOBAL,
            'assign_policy' => Tag::POLICY_EVERYONE,
            'is_system' => true,
            'enabled' => true,
            'position' => 0,
            'auto_rule' => Tag::AUTO_NEW_DOCUMENT,
            'auto_duration_minutes' => (int) config('nextsearch.tags.new_duration_minutes'),
        ])->save();

        $this->info('System tag "new" created.');
    }

    private function ensureAdmin(): void
    {
        $email = config('nextsearch.admin.email');
        $password = config('nextsearch.admin.password');

        if (blank($email) || blank($password)) {
            $this->warn('ADMIN_EMAIL or ADMIN_PASSWORD is missing — no admin created.');

            return;
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            // An existing account is not overwritten. Whoever forgot the
            // password resets it in the interface.
            if (! $existing->isAdmin()) {
                $existing->forceFill(['role' => User::ROLE_ADMIN])->save();
                $this->info(sprintf('Made account %s an administrator.', $email));
            }

            return;
        }

        User::query()->create([
            'name' => config('nextsearch.admin.name'),
            'email' => $email,
            'password' => $password,
            'role' => User::ROLE_ADMIN,
        ]);

        $this->info(sprintf('Administrator %s created.', $email));
    }

    private function ensureBucket(): void
    {
        $disk = (string) config('nextsearch.preview.disk');

        if ($disk !== 's3') {
            return;
        }

        try {
            $this->createBucketIfMissing($disk);

            // A write says more than an existence check.
            Storage::disk($disk)->put('.nextsearch-ready', (string) now());
            $this->info('Object storage reachable.');
        } catch (Throwable $e) {
            $this->warn('Object storage unreachable: '.$e->getMessage());
            $this->warn('Preview images will only work once the bucket exists.');
        }
    }

    /**
     * The bundled MinIO starts without a bucket. With a real S3 provider the
     * permission for this is usually missing — then the message is swallowed and
     * the bucket has to exist already.
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
            $this->info(sprintf('Bucket "%s" created.', $bucket));
        } catch (Throwable $e) {
            $this->warn(sprintf('Bucket "%s" could not be created: %s', $bucket, $e->getMessage()));
        }
    }

    private function ensureIndex(SearchIndex $index): void
    {
        try {
            $index->configure();
            $this->info(sprintf('Search index "%s" set up.', $index->name()));
        } catch (Throwable $e) {
            $this->warn('Search index unreachable: '.$e->getMessage());
        }
    }
}
