<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A customer's own website linked to Findriva through our plugin. The plugin pulls only what an
        // owner or admin published; Findriva never logs in to the site or holds its passwords.
        Schema::create('site_connectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20); // wordpress
            $table->string('status', 20)->default('pending'); // pending, connected, revoked
            $table->string('pairing_code_hash', 64)->nullable();
            $table->timestamp('pairing_expires_at')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->string('site_url')->nullable();
            $table->string('plugin_version', 20)->nullable();
            $table->string('platform_version', 20)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('manifest')->nullable();         // what the plugin outputs, exactly as approved
            $table->json('published_items')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_connectors');
    }
};
