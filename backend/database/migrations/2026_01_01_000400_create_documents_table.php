<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hält den Zustand einer Datei, nicht ihren Text. Der Volltext liegt im
     * Suchindex und als Blob im Objektspeicher.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('watched_folder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('nextcloud_instance_id')->constrained()->cascadeOnDelete();

            // Die Nextcloud-Datei-ID bleibt über Umbenennungen und Verschiebungen
            // stabil und ist deshalb der Anker der Delta-Erkennung.
            $table->string('oc_file_id')->nullable()->index();
            $table->text('remote_path');
            // Pfade können jede Länge haben, ein Btree-Index über `text` läuft
            // in Postgres irgendwann gegen die Zeilengrenze. Deshalb der Hash.
            $table->char('path_hash', 64);
            $table->string('name');
            $table->string('extension', 32)->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamp('remote_modified_at')->nullable();
            $table->string('etag')->nullable();

            $table->string('text_key')->nullable();
            $table->string('preview_key')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->boolean('ocr_used')->default(false);
            $table->jsonb('metadata')->nullable();

            // pending | indexed | failed | skipped
            $table->string('state', 16)->default('pending')->index();
            $table->text('failure_reason')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('indexed_at')->nullable();

            $table->timestamps();

            $table->unique(['watched_folder_id', 'path_hash']);
            $table->index(['watched_folder_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
