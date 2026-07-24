<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nextcloud_instances', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name');
            $table->string('base_url');
            $table->string('username');
            // Encrypted via the APP_KEY (see the model cast). Recommended is a
            // Nextcloud app password, not an account password.
            $table->text('app_password');
            $table->boolean('verify_tls')->default(true);
            $table->boolean('enabled')->default(true);

            $table->string('health_state', 16)->default('unknown');
            $table->text('health_message')->nullable();
            $table->timestamp('health_checked_at')->nullable();

            $table->timestamps();

            $table->unique(['base_url', 'username']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nextcloud_instances');
    }
};
