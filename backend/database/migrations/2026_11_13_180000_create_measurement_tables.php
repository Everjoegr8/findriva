<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Phase 3: connected search-engine accounts, imported performance data and IndexNow.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 40); // google_search_console
            $table->string('status', 20)->default('connected'); // connected, needs_property, error, revoked
            $table->string('account_email')->nullable();
            $table->json('scopes')->nullable();
            // Tokens are encrypted at rest with the app key and never returned by the API.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('property')->nullable(); // e.g. sc-domain:example.com
            $table->json('available_properties')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->date('data_through')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'provider']);
        });

        Schema::create('search_performance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_connection_id')->constrained()->cascadeOnDelete();
            $table->string('source', 40);
            $table->date('date');
            // grain: total (whole property for the day) or query_page. Totals include anonymised queries
            // that Google leaves out of query rows, so the two must never be summed together.
            $table->string('grain', 20);
            $table->text('query')->nullable();
            $table->text('page')->nullable();
            $table->string('row_hash', 64);
            $table->unsignedInteger('clicks');
            $table->unsignedInteger('impressions');
            $table->decimal('ctr', 8, 6);
            $table->decimal('position', 8, 2);
            $table->timestamps();
            $table->unique(['integration_connection_id', 'row_hash']);
            $table->index(['business_id', 'grain', 'date']);
        });

        Schema::table('domain_properties', function (Blueprint $table) {
            $table->string('verification_method', 40)->nullable();
            $table->string('indexnow_key', 64)->nullable();
            $table->timestamp('indexnow_verified_at')->nullable();
        });

        Schema::create('indexnow_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('host');
            $table->unsignedInteger('url_count');
            $table->json('urls');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('outcome', 20); // accepted, rejected, error
            $table->text('message')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indexnow_submissions');
        Schema::table('domain_properties', function (Blueprint $table) {
            $table->dropColumn(['verification_method', 'indexnow_key', 'indexnow_verified_at']);
        });
        Schema::dropIfExists('search_performance');
        Schema::dropIfExists('integration_connections');
    }
};
