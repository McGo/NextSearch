<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * What the admin API needs on the user side:
     *
     * - a uuid, so another system can address an account without relying on
     *   our auto-increment;
     * - `disabled_at`, so an account can be shut without deleting its tags and
     *   saved searches;
     * - an invitation: the hash of a one-time link that lets someone set their
     *   own password. NextSearch sends no mail — whoever creates the account
     *   passes the link on.
     *
     * Plus the admin flag on API keys. Without it, every key an administrator
     * ever made for a search script could also manage users.
     *
     * And the sub path on index runs: a run limited to one directory must not
     * count as a crawl of the whole folder, or it would push back the next
     * regular one.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid()->nullable()->after('id');
            $table->timestamp('disabled_at')->nullable();
            $table->char('invitation_hash', 64)->nullable()->unique();
            $table->timestamp('invitation_expires_at')->nullable();
        });

        DB::table('users')->whereNull('uuid')->orderBy('id')->each(function (object $user) {
            DB::table('users')->where('id', $user->id)->update(['uuid' => (string) Str::uuid7()]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->uuid()->nullable(false)->change();
            // On its own line: Postgres ignores a unique index chained onto
            // change().
            $table->unique('uuid');
            // An invited account has no password until the link is used.
            $table->string('password')->nullable()->change();
        });

        Schema::table('api_tokens', function (Blueprint $table) {
            $table->boolean('admin')->default(false)->after('last_four');
        });

        Schema::table('index_runs', function (Blueprint $table) {
            $table->text('sub_path')->nullable()->after('full');
        });
    }

    public function down(): void
    {
        Schema::table('index_runs', function (Blueprint $table) {
            $table->dropColumn('sub_path');
        });

        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropColumn('admin');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropUnique(['invitation_hash']);
            $table->dropColumn(['uuid', 'disabled_at', 'invitation_hash', 'invitation_expires_at']);
        });
    }
};
