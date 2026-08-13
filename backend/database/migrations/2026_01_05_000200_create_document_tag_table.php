<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which tag hangs on which document. `user_id` carries the visibility: null
     * is a global assignment everyone sees, a set id is one person's own.
     *
     * The database is the source of truth; the search index only mirrors these
     * rows as `tag_tokens`.
     */
    public function up(): void
    {
        Schema::create('document_tag', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            // null = visible to everyone.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            // Who did it. Null for the automation.
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();

            // manual | auto
            $table->string('source', 16)->default('manual');
            // Set by the automation; the sweep clears what has run out.
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index('document_id');
            $table->index(['tag_id', 'user_id']);
            $table->index('expires_at');
        });

        // Same NULL problem as in the tags table: a global assignment exists at
        // most once per document and tag, a personal one once per user.
        DB::statement('CREATE UNIQUE INDEX document_tag_global_unique ON document_tag (document_id, tag_id) WHERE user_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX document_tag_personal_unique ON document_tag (document_id, tag_id, user_id) WHERE user_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_tag');
    }
};
