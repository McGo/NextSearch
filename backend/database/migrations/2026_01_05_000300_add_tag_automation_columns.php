<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two anchors for the automatic tags.
     *
     * `first_indexed_at` marks a document's first successful indexing and is
     * never written again — a rebuild must not make everything "new" a second
     * time.
     *
     * `baseline_at` is set once a folder's first run has finished. Until then
     * the automation keeps quiet: on the initial crawl the entire stock is new,
     * and a tag on twenty thousand documents says nothing.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->timestamp('first_indexed_at')->nullable()->after('indexed_at');
        });

        Schema::table('watched_folders', function (Blueprint $table) {
            $table->timestamp('baseline_at')->nullable()->after('last_crawled_at');
        });

        // An installation that already has an index gets both marks backdated.
        // Without it, the first file to change after the upgrade would look
        // like a first indexing and wear "Neu" although it has been there for
        // years — and the automation would sit idle for one more crawl.
        DB::table('documents')
            ->whereNotNull('indexed_at')
            ->update(['first_indexed_at' => DB::raw('indexed_at')]);

        DB::table('watched_folders')
            ->whereNotNull('last_crawled_at')
            ->update(['baseline_at' => DB::raw('last_crawled_at')]);
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('first_indexed_at');
        });

        Schema::table('watched_folders', function (Blueprint $table) {
            $table->dropColumn('baseline_at');
        });
    }
};
