<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Freigaben werden ausschließlich in NextSearch gepflegt. Die Dateirechte
     * der Nextcloud spielen hier keine Rolle — siehe docs/permissions.md.
     */
    public function up(): void
    {
        Schema::create('folder_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('watched_folder_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'watched_folder_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_user');
    }
};
