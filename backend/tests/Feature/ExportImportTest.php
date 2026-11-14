<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\ActionTask;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExportImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_exports_are_tenant_scoped_logged_and_formula_safe(): void
    {
        Queue::fake();
        [$owner, $ws] = $this->member();
        $id = $this->as($owner, $ws)->postJson('/api/v1/businesses', ['name' => 'Glow by Ada', 'primary_category' => 'Makeup artist'])->json('data.id');
        ActionTask::withoutGlobalScopes()->create(['workspace_id' => $ws->id, 'business_id' => $id, 'title' => '=HYPERLINK("http://evil.example","click")', 'priority' => 1]);

        $res = $this->as($owner, $ws)->get("/api/v1/businesses/{$id}/export/actions")->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=glow-by-ada-actions-', $res->headers->get('Content-Disposition'));
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFTitle,Category,Status", $csv);
        $this->assertStringContainsString("\"'=HYPERLINK(", $csv);
        $this->assertTrue(AuditLog::query()->where('action', 'export.downloaded')->exists());

        foreach (['issues', 'keywords', 'rankings', 'reviews', 'profiles', 'search', 'ai-answers'] as $type) {
            $this->as($owner, $ws)->get("/api/v1/businesses/{$id}/export/{$type}")->assertOk();
        }
        $this->as($owner, $ws)->get("/api/v1/businesses/{$id}/export/passwords")->assertNotFound();

        [$other, $otherWs] = $this->member();
        $this->app['auth']->forgetGuards();
        $this->as($other, $otherWs)->get("/api/v1/businesses/{$id}/export/actions")->assertNotFound();

        [$viewer] = $this->member(WorkspaceRole::Viewer, $ws);
        $this->app['auth']->forgetGuards();
        $this->as($viewer, $ws)->get("/api/v1/businesses/{$id}/export/actions")->assertOk();
    }

    public function test_profile_links_import_from_a_file_and_reports_what_it_skipped(): void
    {
        Queue::fake();
        [$owner, $ws] = $this->member();
        $id = $this->as($owner, $ws)->postJson('/api/v1/businesses', ['name' => 'Glow by Ada', 'primary_category' => 'Makeup artist'])->json('data.id');
        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/assets", ['url' => 'https://instagram.com/glowbyada'])->assertCreated();

        $file = UploadedFile::fake()->createWithContent('links.csv', "platform,link\ninstagram,https://instagram.com/glowbyada\ntiktok,https://www.tiktok.com/@glowbyada\nfacebook,https://facebook.com/glowbyada\n");
        $res = $this->as($owner, $ws)->post("/api/v1/businesses/{$id}/assets/import", ['file' => $file], ['Accept' => 'application/json'])->assertCreated();
        $res->assertJsonPath('data.added', 2)->assertJsonPath('data.skipped.0.url', 'https://instagram.com/glowbyada');
        $this->assertCount(3, $this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}/assets")->json('data'));

        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/assets/import", ['text' => 'no links here'])->assertUnprocessable();
        $many = implode("\n", array_map(fn ($i) => "https://example.com/p{$i}", range(1, 51)));
        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/assets/import", ['text' => $many])->assertUnprocessable();

        [$viewer] = $this->member(WorkspaceRole::Viewer, $ws);
        $this->app['auth']->forgetGuards();
        $this->as($viewer, $ws)->postJson("/api/v1/businesses/{$id}/assets/import", ['text' => 'https://x.com/glow'])->assertForbidden();
    }
}
