<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_connections', function (Blueprint $table) {
            // What the platform shows on the profile, for comparison with the business's own facts.
            $table->json('profile_snapshot')->nullable();
        });

        Schema::create('profile_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_connection_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('reviewer_name')->nullable();
            $table->unsignedTinyInteger('rating')->nullable(); // 1-5; null when Google gives none
            $table->text('comment')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            // The reply as it is live on the platform.
            $table->text('reply_comment')->nullable();
            $table->timestamp('reply_updated_at')->nullable();
            // Our reply workflow: none, draft, awaiting_approval, posting, posted, failed.
            $table->string('reply_status', 20)->default('none');
            $table->text('reply_draft')->nullable();
            $table->foreignId('drafted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->text('reply_error')->nullable();
            $table->timestamps();
            $table->unique(['integration_connection_id', 'external_id']);
            $table->index(['business_id', 'reviewed_at']);
        });

        Schema::create('profile_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_connection_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('metric', 60);
            $table->unsignedInteger('value');
            $table->timestamps();
            $table->unique(['integration_connection_id', 'date', 'metric']);
            $table->index(['business_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_metrics');
        Schema::dropIfExists('profile_reviews');
        Schema::table('integration_connections', fn (Blueprint $t) => $t->dropColumn('profile_snapshot'));
    }
};
