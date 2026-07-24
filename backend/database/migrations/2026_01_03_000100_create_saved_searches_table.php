<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user's stored searches — the query, the picked facets and the sort, so
     * a search can be recalled with one click. Belongs to the user, nothing to
     * do with folder access.
     */
    public function up(): void
    {
        Schema::create('saved_searches', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('query')->nullable();
            // facet => list<string>, the same shape the search endpoint takes.
            $table->jsonb('filters')->default('{}');
            $table->string('sort', 32)->default('relevance');

            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
    }
};
