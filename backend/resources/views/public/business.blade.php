@php
    $loc = $facts['primary_location'];
    $phone = $facts['phones'][0] ?? null;
    $wa = $facts['whatsapp'][0] ?? null;
    $email = $facts['emails'][0] ?? null;
    $fmtPrice = fn ($p) => $p['price'] !== null && $p['currency'] ? ($p['currency'] === 'NGN' ? '₦' : $p['currency'].' ').number_format($p['price'], $p['price'] == floor($p['price']) ? 0 : 2) : null;
@endphp
<!doctype html>
<html lang="{{ $facts['language'] ?: 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @if ($metaDescription)<meta name="description" content="{{ $metaDescription }}">@endif
    <link rel="canonical" href="{{ $url }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $facts['name'] }}">
    <meta property="og:title" content="{{ $title }}">
    @if ($metaDescription)<meta property="og:description" content="{{ $metaDescription }}">@endif
    <meta property="og:url" content="{{ $url }}">
    @if ($facts['image'])<meta property="og:image" content="{{ $facts['image'] }}">@endif
    <meta name="twitter:card" content="{{ $facts['image'] ? 'summary_large_image' : 'summary' }}">
    <script type="application/ld+json">{!! $jsonLd !!}</script>
    <style>
        :root { --bg: #f7f7f4; --card: #fff; --text: #1d1d1b; --muted: #5f5f5a; --line: #e4e4de; --accent: #0f6b4f; --accent-text: #fff; }
        @media (prefers-color-scheme: dark) { :root { --bg: #141413; --card: #1e1e1c; --text: #f0f0eb; --muted: #a8a8a0; --line: #33332f; --accent: #3fb68b; --accent-text: #0b1f17; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 760px; margin: 0 auto; padding: 24px 16px 48px; }
        header h1 { font-size: 1.9rem; line-height: 1.2; margin: 0 0 4px; }
        .sub { color: var(--muted); margin: 0; }
        .cta { display: flex; flex-wrap: wrap; gap: 8px; margin: 20px 0 8px; }
        .cta a { display: inline-block; padding: 10px 16px; border-radius: 999px; text-decoration: none; font-weight: 600; border: 1px solid var(--accent); color: var(--accent); }
        .cta a.primary { background: var(--accent); color: var(--accent-text); }
        section { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 16px 18px; margin-top: 16px; }
        h2 { font-size: 1.1rem; margin: 0 0 10px; }
        ul.plain { list-style: none; padding: 0; margin: 0; }
        ul.plain li { padding: 8px 0; border-top: 1px solid var(--line); }
        ul.plain li:first-child { border-top: 0; padding-top: 0; }
        .row { display: flex; justify-content: space-between; gap: 12px; }
        .muted { color: var(--muted); }
        table { border-collapse: collapse; width: 100%; }
        td { padding: 4px 0; vertical-align: top; }
        td:first-child { width: 40%; color: var(--muted); }
        a { color: var(--accent); overflow-wrap: anywhere; }
        footer { margin-top: 28px; color: var(--muted); font-size: .85rem; text-align: center; }
    </style>
</head>
<body>
<main>
    <header>
        <h1>{{ $facts['name'] }}</h1>
        <p class="sub">{{ collect([$facts['category'], $loc['city'] ?? null])->filter()->join(' · ') }}</p>
    </header>

    <nav class="cta" aria-label="Contact">
        @if ($phone)<a class="primary" href="tel:{{ $phone }}">Call {{ $phone }}</a>@endif
        @if ($wa)<a class="{{ $phone ? '' : 'primary' }}" href="https://wa.me/{{ preg_replace('/\D+/', '', $wa) }}" rel="nofollow">WhatsApp</a>@endif
        @if ($email)<a href="mailto:{{ $email }}">Email</a>@endif
        @if ($facts['website'])<a href="{{ $facts['website'] }}" rel="noopener">Website</a>@endif
    </nav>

    @if ($facts['description'])
        <section><h2>About</h2><p style="margin:0;white-space:pre-line">{{ $facts['description'] }}</p></section>
    @endif

    @if ($facts['services'])
        <section>
            <h2>Services</h2>
            <ul class="plain">
                @foreach ($facts['services'] as $s)
                    <li><strong>{{ $s['name'] }}</strong>@if ($s['description'])<br><span class="muted">{{ $s['description'] }}</span>@endif</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($facts['products'])
        <section>
            <h2>Products</h2>
            <ul class="plain">
                @foreach ($facts['products'] as $p)
                    <li><div class="row"><strong>{{ $p['name'] }}</strong>@if ($fmtPrice($p))<span>{{ $fmtPrice($p) }}</span>@endif</div>@if ($p['description'])<span class="muted">{{ $p['description'] }}</span>@endif</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($loc && $loc['hours'])
        <section>
            <h2>Opening hours</h2>
            <table>
                @foreach ($days as $key => $day)
                    <tr><td>{{ $day }}</td><td>{{ isset($loc['hours'][$key]) ? collect($loc['hours'][$key])->map(fn ($w) => $w[0].' – '.$w[1])->join(', ') : 'Closed' }}</td></tr>
                @endforeach
            </table>
        </section>
    @endif

    @if ($loc || $facts['areas_served'])
        <section>
            <h2>{{ $loc && $loc['street'] ? 'Find us' : 'Where we work' }}</h2>
            @if ($loc && $loc['street'])
                <p style="margin:0 0 8px">{{ collect([$loc['street'], $loc['city'], $loc['region']])->filter()->join(', ') }}</p>
                <a href="https://www.google.com/maps/search/?api=1&query={{ urlencode(collect([$facts['name'], $loc['street'], $loc['city']])->filter()->join(', ')) }}" rel="nofollow noopener">Open in Maps</a>
            @endif
            @if ($facts['areas_served'])
                <p class="muted" style="margin:8px 0 0">Serving {{ collect($facts['areas_served'])->join(', ', ' and ') }}.</p>
            @endif
        </section>
    @endif

    @if ($facts['profiles'])
        <section>
            <h2>Find us online</h2>
            <ul class="plain">
                @foreach ($facts['profiles'] as $pr)
                    <li><a href="{{ $pr['url'] }}" rel="noopener me">{{ \Illuminate\Support\Str::headline($pr['platform']) }}{{ $pr['handle'] ? ' · '.$pr['handle'] : '' }}</a></li>
                @endforeach
            </ul>
        </section>
    @endif

    <footer>
        <p>Details provided by {{ $facts['name'] }}. Last updated {{ $updatedAt->format('j M Y') }}.</p>
        <p>Page made with Findriva.</p>
    </footer>
</main>
</body>
</html>
