# Roadmap, decisions and integration checklist

## Owner decisions taken for the first build

These are the blueprint's "decisions to validate" (section 21), decided so the build could move. Each is cheap to change.

| Decision | Choice | Why |
| --- | --- | --- |
| First market | Nigeria-first (Lagos), global-ready | Phone normalisation defaults to +234; all copy works anywhere |
| First segment | Social-first micro and small businesses, with agency workspaces supported from day one | Biggest underserved group; agencies are the fastest distribution channel |
| Website | Optional. Only a name is needed to start | Most target businesses live on Instagram/WhatsApp |
| Plans | Free snapshot (1 business, 25 pages), Starter, Growth, Agency | Plans buy scope (businesses, locations, pages, audits), never ranking |
| Promise | Process and deliverables, not positions | Rankings belong to search engines; guarantees create refund and legal risk |
| Accounts | We never take passwords; customers connect accounts through official sign-in | Security and platform policy |
| Approvals | Anything touching a customer channel needs owner/admin approval | Trust; prevents costly mistakes (robots, canonicals, DNS) |

## Phase status

| Phase | Item | Status |
| --- | --- | --- |
| 1 | Auth, tenancy, roles, audit log | Done |
| 1 | Business identity graph, website-optional onboarding | Done |
| 1 | Digital asset registry + public profile check | Done |
| 1 | Safe crawler + technical audit (40 checks with evidence) | Done |
| 1 | Identity checks and conflict detection | Done |
| 1 | Action Centre with approvals and re-check verification | Done |
| 1 | Coverage limits, usage events, operations console | Done |
| 1 | Billing provider: Paystack (NGN) checkout, verified webhooks, renewals, lapse after 3-day grace | Done (live once the Paystack account keys are set) |
| 1 | Email verification, password reset, authenticator-app MFA with recovery codes | Done (emails send once Resend is configured) |
| 2 | Keyword provider adapter (`KeywordResearchProvider`) + DataForSEO, 30-day cache, daily spend cap per workspace | Done (live data once the DataForSEO account is funded) |
| 2 | Keyword clustering and intent labelling | Done |
| 2 | SERP/competitor adapter | Next |
| 2 | Content Studio: drafts only from approved facts, claim checker, approvals, version history; optional Claude polishing | Done |
| 2 | Generated fixes: robots.txt, sitemap.xml, LocalBusiness JSON-LD, Open Graph tags | Done |
| 2 | Hosted one-page public page (server-rendered, quality gate, owner/admin publish) | Done |
| 3 | Google Search Console OAuth (read), nightly import, domain verification | Done (live once the Google OAuth client exists) |
| 3 | IndexNow (Bing, Yandex, Naver, Seznam) with per-site key check | Done |
| 3 | Bing Webmaster Tools API (reports) | Blocked on credentials |
| 3 | Results page and monthly report (print/PDF) from measured data | Done |
| 3 | Rank observations via licensed provider | Planned |
| 5 | Google Business Profile API, CMS connectors (WordPress, Shopify), local grid, reviews, AI answer visibility | Planned |

## External integrations that need credentials or approval

None of these are faked; the product shows "not connected" until they exist.

| Integration | What is needed | Owner action |
| --- | --- | --- |
| Google Search Console | Google Cloud project, OAuth consent screen (verification for sensitive scopes), client id/secret | Create project and submit consent screen |
| Google Business Profile API | Access request approved by Google | Apply; can take weeks |
| Bing Webmaster Tools | API key / OAuth app | Register app |
| IndexNow | Per-site key file hosted on the customer domain | Generated per site in Phase 3 |
| Keyword / SERP data | Licensed provider account (e.g. DataForSEO) and budget | Choose provider, fund account |
| Billing | Paystack merchant account (live secret/public keys, webhook URL set to `/api/v1/billing/paystack/webhook`), optional plan codes | Activate Paystack business; add keys |
| Email | Resend account and verified sending domain | Create account, add DNS records |
| Object storage | S3-compatible bucket | For reports and media in Phase 2 |

## Environment variables

See `backend/.env.example`. Findriva-specific:

| Variable | Default | Purpose |
| --- | --- | --- |
| `FINDRIVA_CRAWLER_UA` | `FindrivaBot/0.1 (+https://findriva.com/bot)` | Crawler user agent |
| `FINDRIVA_CRAWLER_MAX_DEPTH` | 3 | Link depth |
| `FINDRIVA_CRAWLER_TIMEOUT` | 10 | Seconds per request |
| `FINDRIVA_CRAWLER_MAX_BYTES` | 2097152 | Max response size |
| `FINDRIVA_CRAWLER_MAX_RUNTIME` | 300 | Seconds per audit |
| `FINDRIVA_CRAWLER_DELAY_MS` | 250 | Politeness delay between pages |
| `FINDRIVA_CRAWLER_ALLOW_PRIVATE` | false | Local development only; ignored in production |
| `FINDRIVA_PUBLIC_URL` | `APP_URL` | Base URL of public pages (`/b/{slug}`), sitemap and robots |
| `FINDRIVA_KEYWORD_PROVIDER` | empty | `dataforseo` to measure volumes; empty = seeds only, "not measured" |
| `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD` | empty | DataForSEO API credentials |
| `FINDRIVA_CONTENT_GENERATOR` | `template` | `claude` to let Claude polish drafts (needs `ANTHROPIC_API_KEY`) |
| `FINDRIVA_CLAUDE_MODEL` | `claude-opus-5-5` | Model for AI drafts; server-side refusal fallbacks are on |
| `ANTHROPIC_API_KEY` | empty | Anthropic API key (optional) |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | empty | Google OAuth client for Search Console |
| `GOOGLE_REDIRECT_URI` | `APP_URL/api/v1/integrations/google/callback` | Must match the OAuth client exactly |
| `FINDRIVA_APP_URL` | `APP_URL` | Dashboard URL to return to after connecting |
| `FINDRIVA_INDEXNOW_KEY` | empty | Platform key for public pages; served at `/{key}.txt` |
| `MAIL_MAILER`, `RESEND_API_KEY`, `MAIL_FROM_ADDRESS` | `log` | `resend` in production; emails are written to the log until then |
| `PAYSTACK_SECRET_KEY`, `PAYSTACK_PUBLIC_KEY` | empty | Paystack keys; checkout shows "not set up yet" without them |
| `PAYSTACK_PLAN_STARTER/GROWTH/AGENCY` | empty | Optional plan codes for automatic renewal |
| `FINDRIVA_PRICE_STARTER/GROWTH/AGENCY` | 990000 / 2990000 / 9900000 | Monthly price in kobo |

Daily limits per plan live in `backend/config/findriva.php`: `keyword_spend_usd_per_day` (free 0, starter 0.50,
growth 2.00, agency 10.00) and `content_generations_per_day` (5 / 30 / 100 / 500).

## Known limitations

- The crawler does not render JavaScript. Sites built entirely client-side will look thin; a sandboxed headless worker is planned.
- Social platforms often show a login wall to anonymous visitors, so profile checks may report "limited". That is shown honestly.
- Audit issues are grouped per issue type (with every affected page in the evidence) to keep the action list short.
