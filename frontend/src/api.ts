// Thin API client for the Findriva Laravel API.

const TOKEN_KEY = 'findriva.token'
const WORKSPACE_KEY = 'findriva.workspace'

function safeGet(key: string): string | null {
  try { return localStorage.getItem(key) } catch { return null }
}
function safeSet(key: string, value: string | null) {
  try {
    if (value === null) localStorage.removeItem(key)
    else localStorage.setItem(key, value)
  } catch { /* storage unavailable */ }
}

export const session = {
  get token() { return safeGet(TOKEN_KEY) },
  set token(v: string | null) { safeSet(TOKEN_KEY, v) },
  get workspaceId() { return safeGet(WORKSPACE_KEY) },
  set workspaceId(v: string | null) { safeSet(WORKSPACE_KEY, v) },
}

export class ApiError extends Error {
  status: number
  errors: Record<string, string[]>
  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }
}

export async function api<T = unknown>(path: string, init: RequestInit & { json?: unknown } = {}): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (session.token) headers.set('Authorization', `Bearer ${session.token}`)
  if (session.workspaceId) headers.set('X-Workspace-Id', session.workspaceId)
  let body = init.body
  if (init.json !== undefined) {
    headers.set('Content-Type', 'application/json')
    body = JSON.stringify(init.json)
  }
  const res = await fetch(`/api/v1${path}`, { ...init, headers, body })
  if (res.status === 204) return undefined as T
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    if (res.status === 401) session.token = null
    throw new ApiError(res.status, data.message ?? `Request failed (${res.status})`, data.errors ?? {})
  }
  return data as T
}

// ---- Types (mirrors the API resources)

export type Role = 'owner' | 'admin' | 'editor' | 'viewer'
export interface WorkspaceSummary { id: number; name: string; type: string; plan: string; role: Role }
export interface User { id: number; name: string; email: string; is_platform_admin: boolean; current_workspace_id: number | null; workspaces: WorkspaceSummary[] }

export interface Location {
  id: number; label: string | null; address_line1: string | null; city: string | null; region: string | null; country: string
  location_type: string; service_areas: string[] | null; opening_hours: Record<string, string[]> | null; is_primary: boolean
}
export interface Contact { id: number; type: 'phone' | 'email' | 'whatsapp' | 'url'; value: string; normalized_value: string | null; is_primary: boolean }
export interface NamedItem { id: number; name: string; description: string | null }
export interface Asset {
  id: number; platform: string; url: string; handle: string | null; ownership_status: string; connection_state: string
  scan_status: 'pending' | 'ok' | 'limited' | 'unreachable'; scan_data: Record<string, unknown> | null; last_scanned_at: string | null
}
export interface CrawlRun {
  id: number; start_url: string; status: 'queued' | 'running' | 'completed' | 'failed' | 'cancelled'
  pages_checked: number; issues_found: number; error: string | null; created_at: string; finished_at: string | null
  site: null | {
    final_url: string; uses_https: boolean; http_redirects_to_https: boolean | null
    robots: { present: boolean; status: number | null; blocks_all: boolean }
    sitemap: { found: boolean; url: string | null; valid: boolean | null; url_count: number }
    budget: { max_pages: number; stopped_reason: string | null }
    reconciliation: { created: number; updated: number; resolved: number } | null
  }
}
export interface Business {
  id: number; name: string; legal_name: string | null; description: string | null; primary_category: string | null
  country: string; website_url: string | null; has_website: boolean; status: string
  locations_count?: number; assets_count?: number; open_issues_count?: number
  aliases?: { id: number; alias: string }[]; locations?: Location[]; contacts?: Contact[]; services?: NamedItem[]; products?: NamedItem[]
  assets?: Asset[]; latest_audit?: CrawlRun | null
}
export interface Completeness { score: number; components: Record<string, boolean>; missing: string[] }
export interface Conflict { field: string; message: string; values: string[] }
export interface BusinessDetail { data: Business; identity: { completeness: Completeness; conflicts: Conflict[] } }

export type Severity = 'critical' | 'high' | 'medium' | 'low' | 'info'
export interface Issue {
  id: number; code: string; category: string; severity: Severity; status: string; title: string; technical_name: string | null
  url: string | null; why_it_matters: string; what_we_found: Record<string, unknown> | null; recommendation: string
  access_needed: string | null; verification_method: string; action?: { id: number; status: string } | null
}
export type ActionStatus = 'open' | 'awaiting_approval' | 'approved' | 'in_progress' | 'blocked' | 'done' | 'verified' | 'dismissed'
export interface Action {
  id: number; title: string; description: string | null; category: string; priority: number; impact: string; effort: string
  access_needed: string | null; requires_approval: boolean; status: ActionStatus; allowed_next: ActionStatus[]
  verification: { result: string; checked_at: string } | null; approved_at: string | null; verified_at: string | null
  issue: { id: number; code: string; severity: Severity; url: string | null; why_it_matters: string; verification_method: string } | null
}
export interface Paginated<T> { data: T[]; meta?: { total: number; current_page: number; last_page: number } }
export interface Overview {
  identity_completeness: Completeness
  website: null | { url: string; last_audit: Partial<CrawlRun> | null; open_issues_by_severity: Record<string, number> }
  profiles: { registered: number; reachable: number; by_platform: Record<string, number> }
  actions: { open: number; awaiting_approval: number; done_unverified: number; verified: number }
  search_engine_data: Record<string, { status: string; note?: string; property?: string | null; data_through?: string | null; last_28_days?: { clicks: number; impressions: number } }>
  open_issues_by_category: Record<string, number>
}
