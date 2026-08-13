<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Personal API keys. A token belongs to one user and carries exactly their
     * permissions — the same folder access, the same tags.
     *
     * Only the SHA-256 hash is stored. The plain text is shown once on creation
     * and cannot be recovered afterwards.
     */
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->char('token_hash', 64)->unique();
            // The last four characters, so a key can be told apart in the list.
            $table->char('last_four', 4);

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
