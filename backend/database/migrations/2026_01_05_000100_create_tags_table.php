<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tag vocabulary of one installation. Two kinds live here side by side:
     * instance tags maintained by an administrator (`owner_id` null) and the
     * private tags a user creates for themselves (`owner_id` set).
     *
     * `scope` decides who sees an assignment, not who sees the tag: a `global`
     * tag's assignment applies to everyone, a `personal` one only to whoever
     * made it.
     */
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            // Stable handle for the API. Unique per owner, see the partial
            // indexes below.
            $table->string('slug', 64);
            // Null only for system tags — the interface translates those.
            $table->string('name')->nullable();
            $table->string('color', 32)->default('neutral');
            $table->text('description')->nullable();

            // global | personal
            $table->string('scope', 16)->default('global');
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();

            // everyone | admin — who may hang a global tag on a document.
            $table->string('assign_policy', 16)->default('everyone');

            // Shipped with the installation: not deletable, slug and scope fixed.
            $table->boolean('is_system')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            // null | new_document
            $table->string('auto_rule', 32)->nullable();
            $table->unsignedInteger('auto_duration_minutes')->nullable();

            $table->timestamps();

            $table->index(['scope', 'enabled']);
            $table->index('auto_rule');
        });

        // A slug is unique within its owner. Postgres and SQLite both treat
        // NULLs in a plain unique index as distinct, so the instance vocabulary
        // needs an index of its own — the Blueprint has no partial indexes.
        DB::statement('CREATE UNIQUE INDEX tags_slug_instance_unique ON tags (slug) WHERE owner_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX tags_slug_owner_unique ON tags (owner_id, slug) WHERE owner_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
