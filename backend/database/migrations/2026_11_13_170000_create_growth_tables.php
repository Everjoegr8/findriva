<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Phase 2: public business page, keyword research and the Content Studio.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('public_slug', 120)->nullable()->unique();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('keyword_clusters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('intent', 20);
            $table->string('theme', 30); // service, product, category, brand, question
            $table->timestamps();
            $table->unique(['business_id', 'name']);
        });

        Schema::create('keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('keyword_cluster_id')->nullable()->constrained()->nullOnDelete();
            $table->string('keyword');
            $table->string('normalized');
            $table->string('intent', 20); // transactional, local, commercial, informational, navigational
            // Where the phrase came from: seed (built from the business's own facts) or a named provider.
            $table->string('origin', 30)->default('seed');
            $table->json('origin_detail')->nullable();
            $table->string('market', 10)->default('NG');
            $table->string('language', 10)->default('en');
            // Measurements are null until a provider measured them. We never estimate them ourselves.
            $table->unsignedInteger('search_volume')->nullable();
            $table->decimal('competition', 5, 4)->nullable();
            $table->decimal('cpc', 10, 2)->nullable();
            $table->string('cpc_currency', 3)->nullable();
            $table->string('metrics_provider', 30)->nullable();
            $table->timestamp('metrics_retrieved_at')->nullable();
            $table->string('status', 20)->default('suggested'); // suggested, tracked, dismissed
            $table->timestamps();
            $table->unique(['business_id', 'normalized', 'market']);
            $table->index(['business_id', 'status']);
        });

        // Provider responses are public market data, cached across tenants to cap spend.
        Schema::create('provider_cache', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('request_hash', 64)->unique();
            $table->json('response');
            $table->timestamp('retrieved_at');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        Schema::create('content_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40);
            $table->string('title');
            $table->string('status', 20)->default('draft'); // draft, in_review, approved, rejected
            $table->unsignedInteger('current_version')->default(1);
            $table->unsignedInteger('approved_version')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'type', 'status']);
        });

        Schema::create('content_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->text('body');
            $table->string('generator', 30); // template, claude, human
            $table->json('source_facts')->nullable();
            $table->json('missing_facts')->nullable();
            $table->json('claim_flags')->nullable();
            $table->timestamps();
            $table->unique(['content_asset_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_versions');
        Schema::dropIfExists('content_assets');
        Schema::dropIfExists('provider_cache');
        Schema::dropIfExists('keywords');
        Schema::dropIfExists('keyword_clusters');
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_by');
            $table->dropUnique(['public_slug']);
            $table->dropColumn(['public_slug', 'is_published', 'published_at']);
        });
    }
};
