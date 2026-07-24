<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Holds a file's state, not its text. The full text lives in the search
     * index and as a blob in object storage.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('watched_folder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('nextcloud_instance_id')->constrained()->cascadeOnDelete();

            // The Nextcloud file id stays stable across renames and moves and
            // is therefore the anchor of the delta detection.
            $table->string('oc_file_id')->nullable()->index();
            $table->text('remote_path');
            // Paths can be any length; a btree index over `text` eventually runs
            // into Postgres's row-size limit. Hence the hash.
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
