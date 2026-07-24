<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watched_folders', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('nextcloud_instance_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('label');
            // Pfad relativ zum WebDAV-Wurzelverzeichnis des Instanz-Benutzers,
            // ohne führenden Slash, z. B. "Dokumente/Rechnungen".
            $table->string('remote_path');
            $table->string('oc_file_id')->nullable();

            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('interval_minutes')->default(15);
            $table->jsonb('exclude_patterns')->nullable();

            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamp('crawl_requested_at')->nullable();

            $table->timestamps();

            $table->unique(['nextcloud_instance_id', 'remote_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watched_folders');
    }
};
