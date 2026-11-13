# API reference (v1)

Base URL `/api/v1`. JSON only. Authenticate with `Authorization: Bearer <token>`. Tenant-scoped routes take
`X-Workspace-Id: <id>` (falls back to the user's last workspace). Lists are paginated (`?page=`, `?per_page=` up to 100).
Validation errors return 422 with `errors`; another tenant's records return 404.

## Auth
| Method | Path | Notes |
| --- | --- | --- |
| POST | `/auth/register` | `name, email, password, workspace_name?, workspace_type? (business\|agency)` → token + user |
| POST | `/auth/login` | `email, password` → token + user |
| POST | `/auth/logout` | revokes current token |
| GET | `/auth/me` | user + workspaces and roles |

## Workspaces
| Method | Path | Permission |
| --- | --- | --- |
| GET/POST | `/workspaces` | any signed-in user (POST makes you owner) |
| GET/PATCH | `/workspace` | view / `workspace.update` |
| GET/POST | `/workspace/members` | view / `members.manage` (`email, role`) |
| PATCH/DELETE | `/workspace/members/{id}` | `members.manage`; only owners manage owners; last owner protected |
| GET | `/workspace/activity` | audit log for this workspace |
| GET | `/coverage` | plan limits and usage |

Roles: **owner** (everything), **admin** (everything except deleting the workspace and billing), **editor** (create/update
businesses, run audits, update actions), **viewer** (read only). Approving actions needs owner or admin.

## Businesses
| Method | Path | Notes |
| --- | --- | --- |
| GET | `/businesses` | `?search=&status=` |
| POST | `/businesses` | Onboarding. Only `name` required. Optional nested `aliases[], locations[], contacts[], services[], products[], assets[]`, `website_url`, `start_audit` (default true when a website is given). Returns business + `identity.completeness` + `identity.conflicts` |
| GET/PATCH/DELETE | `/businesses/{id}` | |
| GET | `/businesses/{id}/overview` | separate measures: identity completeness, website, profiles, actions, search-engine data status |
| GET/POST | `/businesses/{id}/{aliases\|locations\|contacts\|services\|products}` | |
| PATCH/DELETE | `/businesses/{id}/{type}/{recordId}` | |

## Digital assets
| Method | Path | Notes |
| --- | --- | --- |
| GET/POST | `/businesses/{id}/assets` | POST `url, platform?, handle?, ownership_status?`; platform auto-detected; public scan queued |
| PATCH/DELETE | `/businesses/{id}/assets/{assetId}` | `connection_state` may only be `not_connected` or `manual` |
| POST | `/businesses/{id}/assets/{assetId}/scan` | re-check the public page |

## Audits and issues
| Method | Path | Notes |
| --- | --- | --- |
| GET | `/businesses/{id}/audits` | crawl runs, newest first |
| POST | `/businesses/{id}/audits` | queue a website audit (202). 422 without a website, if one is running, or plan limit reached |
| GET | `/businesses/{id}/audits/{runId}` | run + site facts (HTTPS, robots, sitemap, budget, what changed) + pages |
| POST | `/businesses/{id}/audits/{runId}/cancel` | queued runs only |
| GET | `/businesses/{id}/issues` | `?status=open\|resolved\|dismissed\|all&category=&severity=`; each issue has `why_it_matters, what_we_found (evidence), recommendation, access_needed, verification_method` |
| PATCH | `/businesses/{id}/issues/{issueId}` | `status: open\|dismissed` |

## Action Centre
| Method | Path | Notes |
| --- | --- | --- |
| GET | `/businesses/{id}/actions` | `?status=open,approved&category=`; sorted by priority |
| POST | `/businesses/{id}/actions` | manual task |
| PATCH | `/businesses/{id}/actions/{actionId}` | `status` (follows `allowed_next`), `assignee_id` (must be a member), `due_at` |
| POST | `/businesses/{id}/actions/{actionId}/request-approval` | → `awaiting_approval` |
| POST | `/businesses/{id}/actions/{actionId}/decision` | `decision: approve\|reject` (owner/admin) |

Status flow: `open → awaiting_approval → approved → in_progress → done → verified`. `verified` is set only by a re-check
that no longer finds the issue. Work that needs approval cannot move to `in_progress`/`done` before it is approved.

## Ready-made fixes and public page
| Method | Path | Notes |
| --- | --- | --- |
| GET | `/businesses/{id}/fixes` | robots.txt, sitemap.xml, LocalBusiness JSON-LD, head/OG tags. Each fix has `content`, `instructions`, `resolves` (issue codes), `open_issue_codes`, `missing_facts`. Website-only fixes are omitted when there is no website. |
| GET | `/businesses/{id}/public-page` | publish state, quality `gate`, `suggested_slug`, `can_publish` |
| POST | `/businesses/{id}/public-page` | `slug?` (owner/admin). 422 until the gate passes (category, description, location, phone, offerings) |
| DELETE | `/businesses/{id}/public-page` | take the page offline (owner/admin) |

Public, server-rendered: `GET /b/{slug}`, `GET /sitemap.xml` (published pages), `GET /robots.txt`.
Private street addresses and service-area addresses are never rendered; only http(s) links are.

## Keywords
| Method | Path | Notes |
| --- | --- | --- |
| GET | `/businesses/{id}/keywords` | `?intent=&status=&cluster_id=`; includes `clusters`, `provider`, `budget` |
| POST | `/businesses/{id}/keywords/research` | builds seeds from facts; measures via the provider within the daily cap. `status`: measured, cached, not_configured, plan_excludes, budget_exhausted, unsupported_market, provider_error, nothing_to_measure |
| PATCH | `/businesses/{id}/keywords/{keywordId}` | `status: suggested\|tracked\|dismissed` |

`search_volume`, `competition` and `cpc` are `null` (shown as "Not measured") unless a provider measured them; each
measured row records `metrics_provider` and `metrics_retrieved_at`.

## Content Studio
| Method | Path | Notes |
| --- | --- | --- |
| GET | `/businesses/{id}/content` | drafts, available `types`, `ai_available` |
| POST | `/businesses/{id}/content` | `type`, `service_id` (service pages), `use_ai?` |
| GET/PATCH/DELETE | `/businesses/{id}/content/{contentId}` | PATCH `body` creates a new version and re-runs the claim check |
| POST | `/businesses/{id}/content/{contentId}/regenerate` | new version from the generator |
| POST | `/businesses/{id}/content/{contentId}/submit` | → `in_review`; refused while blocking flags remain |
| POST | `/businesses/{id}/content/{contentId}/decision` | `decision: approve\|reject`, `note?` (owner/admin) |
| POST | `/businesses/{id}/content/{contentId}/apply` | only approved "About your business" copy; writes the business description. Other types are copy-and-paste; Findriva never posts to customer channels. |

Claim flags: `block` (placeholders, unknown phone/email/link/price, ranking promises, too long, Google policy) and `warn`
(numbers or superlatives not in the facts).

## Operations (platform staff)
`GET /admin/stats`, `GET /admin/workspaces`, `PATCH /admin/workspaces/{id}` (`plan, billing_status`),
`GET /admin/crawl-runs?status=`, `GET /admin/audit-logs?workspace_id=&action=`.
