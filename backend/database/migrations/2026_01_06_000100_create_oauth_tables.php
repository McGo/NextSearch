<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage for the OAuth 2.1 authorization server that sits in front of the MCP
 * endpoint. It exists for one reason: an MCP client such as Claude Desktop
 * cannot be handed a personal key by hand, it registers itself and asks the
 * user in a browser.
 *
 * Nothing here is stored in plain text. Codes and tokens live as SHA-256
 * hashes, exactly like the personal keys in `api_tokens`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->id();
            // What the client calls itself in the Authorization header flow.
            // Public clients (Claude Desktop among them) have no secret.
            $table->string('client_id', 64)->unique();
            $table->string('name', 160);
            $table->string('secret_hash', 64)->nullable();
            $table->string('token_endpoint_auth_method', 32)->default('none');
            $table->json('redirect_uris');
            $table->string('client_uri', 500)->nullable();
            $table->string('logo_uri', 500)->nullable();
            // Whether the client registered itself (RFC 7591) or an
            // administrator entered it. Only the first can be switched off.
            $table->boolean('is_dynamic')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('oauth_authorization_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash', 64)->unique();
            $table->foreignId('oauth_client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('redirect_uri', 500);
            $table->json('scopes');
            // PKCE is mandatory here — S256 only, no plain.
            $table->string('code_challenge', 128);
            $table->string('code_challenge_method', 16);
            // RFC 8707: which resource the token is meant for. Kept for the
            // record; our tokens are opaque and only we ever accept them.
            $table->string('resource', 500)->nullable();
            $table->timestamp('expires_at');
            // A code is good once. The timestamp stays so a replay is
            // recognisable rather than merely unknown.
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('oauth_client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('scopes');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'oauth_client_id']);
        });

        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('oauth_client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The access token issued alongside it. Revoking one takes the
            // other with it.
            $table->foreignId('oauth_access_token_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->json('scopes');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'oauth_client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_refresh_tokens');
        Schema::dropIfExists('oauth_access_tokens');
        Schema::dropIfExists('oauth_authorization_codes');
        Schema::dropIfExists('oauth_clients');
    }
};
