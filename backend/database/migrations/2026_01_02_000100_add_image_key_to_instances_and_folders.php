<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instanzen und Ordner können ein eigenes Bild bekommen. Es liegt im
 * Objektspeicher; hier steht nur der Ablageschlüssel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nextcloud_instances', function (Blueprint $table) {
            $table->string('image_key')->nullable()->after('username');
        });

        Schema::table('watched_folders', function (Blueprint $table) {
            $table->string('image_key')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('nextcloud_instances', fn (Blueprint $table) => $table->dropColumn('image_key'));
        Schema::table('watched_folders', fn (Blueprint $table) => $table->dropColumn('image_key'));
    }
};
