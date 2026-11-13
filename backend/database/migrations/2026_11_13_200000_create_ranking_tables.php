<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One search-results check for one tracked keyword: a snapshot from one place and device, never an average.
        Schema::create('rank_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('keyword_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('search_engine', 20)->default('google');
            $table->string('market', 10);
            $table->string('language', 10);
            $table->string('device', 10)->default('desktop');
            $table->unsignedSmallInteger('depth'); // how many results we looked at
            // Organic position of the business's own site or profile; null = not found within `depth`.
            $table->unsignedSmallInteger('position')->nullable();
            $table->unsignedSmallInteger('absolute_position')->nullable();
            $table->string('url', 2048)->nullable();
            $table->json('serp_features')->nullable();
            $table->json('top_results')->nullable(); // first 10 organic results: position, domain, url, title
            $table->decimal('cost', 10, 5)->default(0);
            $table->timestamp('checked_at');
            $table->timestamps();
            $table->index(['keyword_id', 'checked_at']);
            $table->index(['business_id', 'checked_at']);
        });

        Schema::create('competitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->string('name')->nullable();
            $table->string('kind', 20)->default('business'); // business, platform (directories, social networks, marketplaces)
            $table->string('source', 20)->default('search_results'); // search_results, manual
            $table->string('status', 20)->default('suggested'); // suggested, tracked, dismissed
            $table->unsignedInteger('shared_keywords')->default(0);
            $table->unsignedSmallInteger('best_position')->nullable();
            $table->decimal('average_position', 5, 2)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitors');
        Schema::dropIfExists('rank_observations');
    }
};
