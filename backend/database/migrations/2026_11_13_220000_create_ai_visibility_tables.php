<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Questions customers might ask an AI assistant. The business name is never put in the question.
        Schema::create('ai_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('keyword_id')->nullable()->constrained()->nullOnDelete();
            $table->string('question', 300);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'question']);
        });

        // One answer from one assistant at one moment. A snapshot, not a ranking.
        Schema::create('ai_answer_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_question_id')->constrained()->cascadeOnDelete();
            $table->string('engine', 30);
            $table->string('model', 60);
            $table->string('location', 120)->nullable();
            $table->text('answer');
            $table->boolean('named')->default(false);       // the business name or an alias appears in the answer
            $table->boolean('own_site_cited')->default(false); // one of its own sites or profiles is a cited source
            $table->text('excerpt')->nullable();
            $table->json('sources')->nullable();  // [{url, title, domain, kind: own|platform|other, cited}]
            $table->unsignedSmallInteger('searches')->default(0);
            $table->decimal('cost', 10, 5)->default(0);
            $table->timestamp('checked_at');
            $table->timestamps();
            $table->index(['ai_question_id', 'checked_at']);
            $table->index(['business_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_answer_checks');
        Schema::dropIfExists('ai_questions');
    }
};
