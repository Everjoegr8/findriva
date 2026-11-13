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
| 1 | Billing provider (Paystack/Flutterwave/Stripe) | Not started: needs a merchant account |
| 1 | Email verification, password reset, MFA | Not started (schema has `mfa_enabled_at`) |
| 2 | Keyword provider adapter (`KeywordResearchProvider`) + one licensed provider | Next |
| 2 | SERP/competitor adapter, clustering and intent labelling | Next |
| 2 | Content Studio: drafts only from approved facts, approvals, version history | Next |
| 2 | Generated fixes: robots.txt, sitemap.xml, LocalBusiness JSON-LD, Open Graph tags | Next |
| 2 | Hosted one-page microsite (server-rendered) | Next |
| 3 | Google Search Console OAuth (read) | Blocked on credentials |
| 3 | Bing Webmaster + IndexNow | Blocked on credentials |
| 3 | Rank observations via licensed provider; reports and export | Planned |
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
| Billing | Paystack or Flutterwave (NGN) and/or Stripe | Merchant account |
| Email | SMTP / transactional provider | Account and sending domain |
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

## Known limitations

- The crawler does not render JavaScript. Sites built entirely client-side will look thin; a sandboxed headless worker is planned.
- Social platforms often show a login wall to anonymous visitors, so profile checks may report "limited". That is shown honestly.
- Audit issues are grouped per issue type (with every affected page in the evidence) to keep the action list short.
