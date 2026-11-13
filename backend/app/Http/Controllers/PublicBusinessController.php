<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Services\Facts\BusinessFacts;
use App\Services\Fixes\FixGenerator;
use App\Services\Fixes\JsonLd;
use App\Services\Publishing\PublicPage;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/** Public, server-rendered pages. No tenant is active here; only published pages are served. */
class PublicBusinessController extends Controller
{
    public function __construct(private readonly BusinessFacts $facts) {}

    public function show(string $slug): Response
    {
        $business = Business::withoutGlobalScopes()->where('public_slug', $slug)->where('is_published', true)->firstOrFail();
        $facts = $this->facts->for($business);
        $url = PublicPage::url($business);
        $city = $facts['primary_location']['city'] ?? null;

        return response()->view('public.business', [
            'facts' => $facts,
            'url' => $url,
            'title' => FixGenerator::titleFor($facts['name'], $facts['category'], $city),
            'metaDescription' => $facts['description'] ? Str::limit(preg_replace('/\s+/', ' ', $facts['description']), 155, '…') : null,
            'jsonLd' => JsonLd::encode(JsonLd::localBusiness($facts, $url), false),
            'days' => BusinessFacts::DAYS,
            'updatedAt' => $business->updated_at,
        ])->header('Cache-Control', 'public, max-age=300');
    }

    public function sitemap(): Response
    {
        $pages = Business::withoutGlobalScopes()->where('is_published', true)->whereNotNull('public_slug')
            ->orderBy('id')->limit(50000)->get(['public_slug', 'updated_at']);
        $base = rtrim(config('findriva.public_url'), '/');
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($pages as $p) {
            $xml .= '  <url><loc>'.htmlspecialchars($base.'/b/'.$p->public_slug, ENT_XML1).'</loc><lastmod>'.$p->updated_at->toDateString()."</lastmod></url>\n";
        }

        return response($xml."</urlset>\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(): Response
    {
        $base = rtrim(config('findriva.public_url'), '/');
        $body = "User-agent: *\nAllow: /b/\nDisallow: /api/\n\nSitemap: {$base}/sitemap.xml\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
