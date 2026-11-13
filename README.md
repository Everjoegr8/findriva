# Findriva

Your business deserves to be found. One platform. Every search. Every location. Every opportunity.

**Discover · Audit · Recommend · Approve · Implement · Validate · Measure · Improve**

Findriva is a business discoverability platform. It takes a business from practically invisible online to a properly
structured, discoverable, credible and measurable presence, whether or not it has a website. It is not another SEO
dashboard that lists problems: every finding becomes an action, every action that touches a customer's channel waits for
their approval, and every fix is confirmed by a fresh check rather than by someone saying it is done.

We never promise a number-one ranking, guaranteed indexing or results in 48 hours. Search engines decide rankings. We fix
what's holding a business back, publish what it approves, and measure what changes.

## What's in this repository (Phase 1: foundation)

| Area | Status |
| --- | --- |
| Multi-tenant workspaces (business or agency), roles, tenant isolation at the data layer | Done |
| Accounts and API tokens (Sanctum) | Done |
| Business identity graph: aliases, locations / service areas, contacts, services, products | Done |
| Website-optional onboarding (only the name is required) | Done |
| Digital asset registry with platform detection and a public profile check | Done |
| SSRF-safe crawler (IP pinning, per-hop redirect checks, robots.txt, sitemaps, page and time budgets) | Done |
| Website audit engine: 40 plain-language checks, each with evidence, recommendation and verification method | Done |
| Identity checks that work without a website (completeness score, conflicting details) | Done |
| Action Centre: priorities, approvals, status rules, automatic verification on re-check | Done |
| Audit log, coverage limits per plan, usage tracking, operations console | Done |
| React dashboard (onboarding wizard, overview, audit, Action Centre, profiles, ops) | Done |
| Keyword research, competitor/SERP data, content studio, microsites | Phase 2 |
| Search Console, Bing Webmaster / IndexNow, sitemap deployment, rank tracking, reports | Phase 3 |

See [docs/roadmap.md](docs/roadmap.md) for the full plan and the external integrations that still need credentials or approval.

## Layout

```
backend/    Laravel 13 API (PHP 8.3), PostgreSQL, Redis queues
frontend/   React 19 + TypeScript + Vite dashboard
docs/       Product blueprint, architecture, API reference, roadmap
```

## Quick start (local)

Requirements: PHP 8.3 with pdo_pgsql or pdo_sqlite, Composer, Node 20+.

```bash
# API
cd backend
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite          # or point DB_* at PostgreSQL
php artisan migrate --seed              # seeds clearly labelled demo users
php artisan serve                       # http://127.0.0.1:8000
php artisan queue:work --queue=crawl,scan,default   # audits and profile checks run here

# Dashboard
cd ../frontend
npm install
npm run dev                             # http://127.0.0.1:5173 (proxies /api to :8000)
```

Demo logins from the seeder: `demo@findriva.test` / `demo-password-123` (owner) and `ops@findriva.test` (operations staff).

Or run everything with Docker: `export APP_KEY=base64:$(openssl rand -base64 32) && docker compose up --build` (API on :8000, dashboard on :5173, PostgreSQL, Redis, a queue worker).

## Tests

```bash
cd backend && php artisan test         # 59 tests: tenancy, roles, SSRF, crawler, audit rules, Action Centre
cd frontend && npm run lint && npm run build
```

The suite also runs against PostgreSQL in CI. Tests never touch the network: HTTP is faked and DNS is stubbed.

## Docs

- [Product blueprint](docs/product-blueprint.md): the original PRD and build handoff
- [Architecture](docs/architecture.md): modules, data model, tenancy, crawler safety, security notes
- [API reference](docs/api.md)
- [Roadmap and integration checklist](docs/roadmap.md)
