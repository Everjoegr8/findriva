<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('start_url');
            $table->string('status', 20)->default('queued'); // queued, running, completed, failed, cancelled
            $table->json('settings')->nullable();
            $table->unsignedInteger('pages_checked')->default(0);
            $table->unsignedInteger('issues_found')->default(0);
            $table->json('site_facts')->nullable(); // robots/sitemap/https findings
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'created_at']);
        });

        Schema::create('crawled_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crawl_run_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->text('final_url')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedSmallInteger('depth')->default(0);
            $table->json('redirect_chain')->nullable();
            $table->string('content_type')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->text('title')->nullable();
            $table->text('meta_description')->nullable();
            $table->json('h1')->nullable();
            $table->text('canonical')->nullable();
            $table->string('robots_meta')->nullable();
            $table->string('x_robots_tag')->nullable();
            $table->boolean('indexable')->nullable();
            $table->string('lang', 20)->nullable();
            $table->json('open_graph')->nullable();
            $table->json('structured_data')->nullable();
            $table->json('extras')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('audit_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crawl_run_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('crawled_page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 80);
            $table->string('category', 30); // technical, content, identity, local, social, indexability
            $table->string('severity', 10); // critical, high, medium, low, info
            $table->text('url')->nullable();
            $table->string('title');
            $table->text('why_it_matters');
            $table->text('recommendation');
            $table->text('verification_method');
            $table->json('evidence')->nullable();
            $table->string('status', 20)->default('open'); // open, resolved, dismissed
            $table->timestamps();
            $table->index(['business_id', 'status', 'severity']);
        });

        Schema::create('action_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_issue_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 30)->default('technical');
            $table->unsignedTinyInteger('priority')->default(50); // 0-100, higher first
            $table->string('impact', 10)->default('medium');
            $table->string('effort', 10)->default('medium');
            $table->string('access_needed')->nullable();
            $table->boolean('requires_approval')->default(true);
            $table->string('status', 20)->default('open');
            $table->json('evidence')->nullable();
            $table->json('verification')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_tasks');
        Schema::dropIfExists('audit_issues');
        Schema::dropIfExists('crawled_pages');
        Schema::dropIfExists('crawl_runs');
    }
};
