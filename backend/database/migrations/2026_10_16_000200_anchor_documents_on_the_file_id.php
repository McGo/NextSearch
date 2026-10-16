<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A document is now the Nextcloud file, not the path it happens to lie
     * under. A rename or a move — into another watched folder as well — keeps
     * the uuid, the tags and the link.
     *
     * `vanished_at` makes that work whatever order the crawls run in. A file
     * that is gone from its directory is not deleted straight away: it leaves
     * the search index and waits, with its tags, until a crawl finds it again
     * under its file id somewhere else — or until the retention period is up.
     *
     * `files_moved` counts those relocations per run.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->timestamp('vanished_at')->nullable()->index();
            $table->index(['nextcloud_instance_id', 'oc_file_id']);
        });

        Schema::table('index_runs', function (Blueprint $table) {
            $table->unsignedInteger('files_moved')->default(0)->after('files_updated');
        });
    }

    public function down(): void
    {
        Schema::table('index_runs', function (Blueprint $table) {
            $table->dropColumn('files_moved');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['nextcloud_instance_id', 'oc_file_id']);
            $table->dropIndex(['vanished_at']);
            $table->dropColumn('vanished_at');
        });
    }
};
