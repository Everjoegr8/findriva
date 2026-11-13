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
| GET | `/auth/me` | user (`email_verified`, `mfa_enabled`) + workspaces and roles |
| POST | `/auth/mfa/challenge` | `challenge, code` (authenticator or recovery code) → token. Login returns `{mfa_required, challenge}` when MFA is on; challenges last 5 minutes and allow 5 tries |
| POST | `/auth/password/forgot` | `email`; same answer whether or not the account exists |
| POST | `/auth/password/reset` | `token, email, password, password_confirmation`; signs out every session |
| GET | `/auth/email/verify/{id}/{hash}` | signed link from the email (48 h); redirects to the dashboard with `?verified=1\|invalid` |
| POST | `/auth/email/resend` | resend the verification email (6 per hour) |

Owner actions that reach outside Findriva (inviting members, publishing a page, connecting Google, paying) need a
verified email.

## Account security
| Method | Path | Notes |
| --- | --- | --- |
| GET | `/account/security` | MFA state, recovery codes left |
| POST | `/account/mfa/setup` | new pending secret + `otpauth_url` |
| POST | `/account/mfa/confirm` | `code` → turns MFA on, returns 10 one-time recovery codes (stored hashed) |
| POST | `/account/mfa/disable` | `password, code` |

Codes are TOTP (RFC 6238, 30 s, ±1 step); a code cannot be used twice.

## Billing
| Method | Path | Notes |
| --- | --- | --- |
| GET | `/workspace/billing` | plan, status, period end, prices (kobo, NGN), `configured`, payment history |
| POST | `/workspace/billing/checkout` | owner only, verified email. `plan` → Paystack `authorization_url` |
| POST | `/workspace/billing/confirm` | `reference`; verifies with Paystack and activates the plan if amount and currency match |
| POST | `/billing/paystack/webhook` | public; `x-paystack-signature` (HMAC-SHA512) required. Handles `charge.success`, `subscription.create`, `subscription.not_renew`, `subscription.disable`, `invoice.payment_failed`; each event is processed once |

A paid month runs from payment; renewals extend from the current end. Plans lapse to free 3 days after the period
ends without a renewal (checked daily at 02:00).

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

## Integrations and results
| Method | Path | Notes |
| --- | --- | --- |
| GET | `/businesses/{id}/integrations` | Search Console connection (never tokens), IndexNow key state, domain verification |
| POST | `/businesses/{id}/integrations/google/connect` | owner/admin; returns `authorization_url` (scope `webmasters.readonly`, offline access) |
| GET | `/integrations/google/callback` | public; Google redirects here. One-time `state` (10 min) proves who started it; redirects to the dashboard |
| POST | `/businesses/{id}/integrations/google-business/connect` | owner/admin, verified email; Business Profile (`business.manage`, the only scope Google offers) |
| PATCH | `/businesses/{id}/integrations/{connectionId}` | `property` from `available_properties` (owner/admin); for Business Profile a location `accounts/{a}/locations/{l}`, which resets imported reviews and actions |
| POST | `/businesses/{id}/integrations/{connectionId}/sync` | queue an import (also runs nightly at 04:30) |
| DELETE | `/businesses/{id}/integrations/{connectionId}` | revokes the grant at Google and deletes imported data |
| POST | `/businesses/{id}/indexnow/key` | create the per-site key; returns the file name and content |
| POST | `/businesses/{id}/indexnow/verify` | re-fetches the key file from the site (SSRF-safe) |
| POST | `/businesses/{id}/indexnow/submit` | owner/admin; `urls?` (same host only), default = indexable pages from the last audit |
| GET | `/businesses/{id}/google-profile` | `?days=7\|28\|90`; connection, what the profile shows, `differences` from Findriva facts, profile actions (calls, directions, website clicks, chats, Search and Maps views) vs previous period, review summary |
| GET | `/businesses/{id}/reviews` | `?filter=all\|unanswered\|awaiting_approval\|low` |
| GET | `/businesses/{id}/reviews/{reviewId}/suggestion` | a short reply built from the review and the business's own facts, with claim-check flags |
| PATCH | `/businesses/{id}/reviews/{reviewId}` | `reply` saves a draft (editor); refused while waiting for approval |
| POST | `/businesses/{id}/reviews/{reviewId}/submit` | → `awaiting_approval`; refused while blocking flags remain |
| POST | `/businesses/{id}/reviews/{reviewId}/decision` | owner/admin, verified email. `approve` posts exactly the approved text to Google (502 + `failed` if Google refuses; approve again to retry); `reject` sends it back |
| GET | `/businesses/{id}/rankings` | tracked keywords with latest live position, change vs previous check, 120-day history, page-one results; `summary`, `method` (provider, market, device, depth, cadence), `allowance`, `last_run` |
| POST | `/businesses/{id}/rankings/check` | queue a check (202). Skips keywords checked in the last 20 hours; weekly run Mondays 05:00 |
| GET | `/businesses/{id}/competitors` | `?kind=business\|platform&status=suggested\|tracked\|dismissed\|all`; each with shared keywords, best/average position and head-to-head per keyword |
| POST | `/businesses/{id}/competitors` | `domain, name?` add one you know (tracked) |
| PATCH | `/businesses/{id}/competitors/{competitorId}` | `status`, `kind`, `name` |
| GET | `/businesses/{id}/performance` | `?days=7\|28\|90`; totals vs previous period, top searches/pages, tracked keywords' measured positions |
| GET | `/businesses/{id}/report` | `?month=YYYY-MM`; counted and measured figures only, with a `not_measured` list |

Business Profile imports run nightly at 04:45: profile facts, up to 500 newest reviews, and 90 days of daily actions
(Google leaves out zero days; they are stored as zero, ending 3 days ago). A reply written in Google's own app counts as
answered. Disconnecting deletes imported data and revokes the Google grant unless another connection uses the same
Google account.

Live positions come from a licensed provider (DataForSEO Google organic, country level, desktop, top 30). `position` is
the organic rank of the business's own site (any subdomain) or its exact profile URLs (for example its Instagram
profile or Findriva public page); `null` means not found within the depth, never an estimate. Checks are limited by
the plan's `rank_tracked_keywords` (most-searched first) and `rank_spend_usd_per_day`. Social networks, directories and
marketplaces are labelled `platform`, not competitors.

Imports only finished days (`dataState: final`, 3-day lag), stores daily totals separately from query/page rows
(Google hides some queries, so they never add up), and re-reads the last two days each sync. Owning the matching
Search Console property marks the domain verified (`verification_method: google_search_console`). Published public
pages are announced to IndexNow with `FINDRIVA_INDEXNOW_KEY` when it is set.

## Operations (platform staff)
`GET /admin/stats`, `GET /admin/workspaces`, `PATCH /admin/workspaces/{id}` (`plan, billing_status`),
`GET /admin/crawl-runs?status=`, `GET /admin/audit-logs?workspace_id=&action=`.
