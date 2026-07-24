<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('index_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('watched_folder_id')->constrained()->cascadeOnDelete();

            // running | completed | failed
            $table->string('state', 16)->default('running')->index();
            $table->string('trigger', 16)->default('schedule');
            $table->boolean('full')->default(false);

            $table->unsignedInteger('files_seen')->default(0);
            $table->unsignedInteger('files_new')->default(0);
            $table->unsignedInteger('files_updated')->default(0);
            $table->unsignedInteger('files_removed')->default(0);
            $table->unsignedInteger('files_skipped')->default(0);
            $table->unsignedInteger('files_failed')->default(0);
            $table->unsignedInteger('pending_jobs')->default(0);

            $table->jsonb('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('index_runs');
    }
};
