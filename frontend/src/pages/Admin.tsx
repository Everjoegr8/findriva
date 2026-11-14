import { useState, type FormEvent, type ReactNode } from 'react'
import { Link, Route, Routes, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../api'
import { useAuth } from '../auth'
import { Building2, CheckCircle2, CreditCard, Gauge, Store, Users as UsersIcon, Zap } from 'lucide-react'
import { Badge, Empty, ErrorState, PageTitle, Spinner, StatusBadge, fieldErrors, humanize } from '../ui'
import { DiscoveryPath, type PathState } from '../components/DiscoveryPath'

// ---------- types ----------
interface Stats {
  users: { total: number; new_7d: number; new_30d: number; unverified: number; active_7d: number; disabled: number }
  plans: Record<string, number>; paying_workspaces: number; mrr: number; revenue_30d: number
  connections: { provider: string; status: string; n: number }[]; website_connectors: number; failed_jobs: number; ai_spend_30d_usd: number
  workspaces: number; businesses: number; businesses_without_website: number
  crawl_runs: { queued: number; running: number; failed_7d: number; completed_7d: number }
}
interface Page<T> { data: T[]; total: number; current_page: number; last_page: number }
interface UserRow {
  id: number; name: string; email: string; email_verified: boolean; mfa_enabled: boolean; is_platform_admin: boolean; workspaces_count: number | null
  last_login_at: string | null; created_at: string; disabled_at: string | null; disabled_reason: string | null
}
interface Note { id: number; body: string; created_at: string; author_id: number | null; author: { id: number; name: string } | null }
interface LogRow { id: number; action: string; target_type: string | null; target_id: number | null; workspace_id?: number | null; created_at: string; actor?: { name: string } | null; ip_address?: string | null }
interface WorkspaceRow { id: number; name: string; plan: string; billing_status: string; businesses_count: number; memberships_count: number; created_at: string }
interface Run { id: number; workspace_id: number; business_id: number; start_url: string; status: string; pages_checked: number; issues_found: number; error: string | null; created_at: string }
interface Payment { id: number; workspace_id: number; workspace_name: string | null; reference: string; plan: string; amount: number; currency: string; status: string; paid_at: string | null; created_at: string }
interface FailedJob { id: number; uuid: string; queue: string; job: string; error: string; failed_at: string }
interface Trend { date: string; signups: number; workspaces: number; revenue: number }

const naira = (kobo: number) => '₦' + Math.round(kobo / 100).toLocaleString()
const when = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : 'Never')
const day = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' }) : '')

function Stat({ label, value, hint, to }: { label: string; value: ReactNode; hint?: ReactNode; to?: string }) {
  const body = <><div className="label">{label}</div><div className="value">{value}</div>{hint && <div className="small muted">{hint}</div>}</>
  return to ? <Link to={to} className="card stat admin-stat-link">{body}</Link> : <div className="card stat">{body}</div>
}

function Pager({ page, set }: { page?: { current_page: number; last_page: number; total: number }; set: (n: number) => void }) {
  if (!page || page.last_page <= 1) return page ? <div className="small muted">{page.total} total</div> : null
  return (
    <div className="row small">
      <button className="btn sm" disabled={page.current_page <= 1} onClick={() => set(page.current_page - 1)}>Previous</button>
      <span className="muted">Page {page.current_page} of {page.last_page} · {page.total} total</span>
      <button className="btn sm" disabled={page.current_page >= page.last_page} onClick={() => set(page.current_page + 1)}>Next</button>
    </div>
  )
}

function Notes({ notes, workspaceId, userId, onChange }: { notes: Note[]; workspaceId?: number; userId?: number; onChange: () => void }) {
  const { user } = useAuth()
  const [body, setBody] = useState('')
  const add = useMutation({ mutationFn: () => api('/admin/notes', { method: 'POST', json: { workspace_id: workspaceId, user_id: userId, body } }), onSuccess: () => { setBody(''); onChange() } })
  const del = useMutation({ mutationFn: (id: number) => api(`/admin/notes/${id}`, { method: 'DELETE' }), onSuccess: onChange })
  const submit = (e: FormEvent) => { e.preventDefault(); if (body.trim()) add.mutate() }
  return (
    <div className="card stack">
      <div><h2 style={{ margin: 0 }}>Support notes</h2><p className="small muted" style={{ margin: 0 }}>Only Findriva staff see these. Never write passwords or card details here.</p></div>
      <form onSubmit={submit} className="stack" style={{ gap: '0.5rem' }}>
        <textarea rows={3} value={body} onChange={(e) => setBody(e.target.value)} placeholder="What happened, what you did, what to follow up" aria-label="New note" />
        <div><button className="btn primary sm" disabled={add.isPending || !body.trim()}>Add note</button></div>
      </form>
      {notes.length === 0 ? <p className="small muted" style={{ margin: 0 }}>No notes yet.</p> : (
        <ul className="admin-notes">
          {notes.map((n) => (
            <li key={n.id}>
              <div className="row between small"><strong>{n.author?.name ?? 'Former staff'}</strong><span className="muted">{when(n.created_at)}</span></div>
              <div style={{ whiteSpace: 'pre-wrap' }}>{n.body}</div>
              {n.author_id === user?.id && <button className="btn ghost sm danger" onClick={() => del.mutate(n.id)}>Delete</button>}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function Activity({ rows, showActor = true }: { rows: LogRow[]; showActor?: boolean }) {
  if (!rows.length) return <p className="small muted">No activity yet.</p>
  return (
    <div className="table-wrap">
      <table>
        <thead><tr><th>When</th>{showActor && <th>Who</th>}<th>What</th><th>On</th></tr></thead>
        <tbody>{rows.map((l) => (
          <tr key={l.id}><td className="small">{when(l.created_at)}</td>{showActor && <td>{l.actor?.name ?? 'System'}</td>}<td><code>{l.action}</code></td><td className="small">{l.target_type} {l.target_id}</td></tr>
        ))}</tbody>
      </table>
    </div>
  )
}

// ---------- signups chart: one series, so no legend; the title names it ----------
function SignupChart({ rows }: { rows: Trend[] }) {
  const [hover, setHover] = useState<number | null>(null)
  const max = Math.max(1, ...rows.map((r) => r.signups))
  const W = 720, H = 160, pad = 24, bw = (W - pad) / rows.length
  const total = rows.reduce((s, r) => s + r.signups, 0)
  const h = hover !== null ? rows[hover] : null
  return (
    <div className="card stack" style={{ gap: '0.5rem' }}>
      <div className="row between">
        <h2 style={{ margin: 0 }}>New sign-ups per day</h2>
        <span className="small muted">{total} in the last {rows.length} days</span>
      </div>
      <div style={{ position: 'relative' }}>
        <svg viewBox={`0 0 ${W} ${H + 20}`} width="100%" role="img" aria-label={`Bar chart of daily sign-ups over ${rows.length} days, ${total} in total`} onMouseLeave={() => setHover(null)}>
          <line x1={pad} x2={W} y1={H} y2={H} stroke="var(--border)" />
          <text x={0} y={10} fontSize="10" fill="var(--muted)">{max}</text>
          <text x={0} y={H} fontSize="10" fill="var(--muted)">0</text>
          {rows.map((r, i) => {
            const bh = (r.signups / max) * (H - 8)
            const x = pad + i * bw
            return (
              <g key={r.date} onMouseEnter={() => setHover(i)}>
                <rect x={x} y={0} width={bw} height={H} fill="transparent" />
                {r.signups > 0 && <path d={roundedTop(x + 1, H - bh, Math.max(bw - 2, 1), bh)} fill="var(--brand)" opacity={hover === null || hover === i ? 1 : 0.45} />}
              </g>
            )
          })}
          {[0, Math.floor(rows.length / 2), rows.length - 1].map((i) => rows[i] && (
            <text key={i} x={pad + i * bw + bw / 2} y={H + 14} fontSize="10" fill="var(--muted)" textAnchor="middle">{new Date(rows[i].date).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })}</text>
          ))}
        </svg>
        {h && <div className="admin-tip" style={{ left: `${((pad + hover! * bw + bw / 2) / W) * 100}%` }}><strong>{h.signups}</strong> sign-up{h.signups === 1 ? '' : 's'}<br /><span className="muted">{day(h.date)}{h.revenue ? ` · ${naira(h.revenue)} paid` : ''}</span></div>}
      </div>
    </div>
  )
}
function roundedTop(x: number, y: number, w: number, h: number) {
  const r = Math.min(4, w / 2, h)
  return `M${x},${y + h}V${y + r}Q${x},${y} ${x + r},${y}H${x + w - r}Q${x + w},${y} ${x + w},${y + r}V${y + h}Z`
}

// ---------- pages ----------
function Overview() {
  const stats = useQuery({ queryKey: ['admin', 'stats'], queryFn: () => api<{ data: Stats }>('/admin/stats') })
  const trends = useQuery({ queryKey: ['admin', 'trends'], queryFn: () => api<{ data: Trend[] }>('/admin/trends?days=30') })
  const runs = useQuery({ queryKey: ['admin', 'runs'], queryFn: () => api<Page<Run>>('/admin/crawl-runs?status=failed') })
  if (stats.isLoading) return <Spinner />
  if (stats.error) return <ErrorState error={stats.error} retry={() => stats.refetch()} />
  const s = stats.data!.data
  const on = (n: number): PathState => (n > 0 ? 'live' : 'off')
  return (
    <div className="stack">
      <PageTitle title="Platform overview" sub="Live numbers from the database, nothing estimated. Every staff action is recorded in the activity log." />
      <section className="hero-panel" aria-label="Customer journey across the platform">
        <div className="hero-copy">
          <span className="eyebrow">Customer journey</span>
          <h2>From sign-up to paying customer</h2>
          <p>Each line lights up when real customers have reached that stage.</p>
        </div>
        <DiscoveryPath
          hub={{ label: 'Findriva', detail: `${s.users.active_7d} active this week`, icon: Zap }}
          nodes={[
            { key: 'people', label: 'People', detail: `${s.users.total} signed up`, icon: UsersIcon, state: on(s.users.total), to: '/control/users' },
            { key: 'confirmed', label: 'Confirmed email', detail: `${s.users.total - s.users.unverified} confirmed`, icon: CheckCircle2, state: on(s.users.total - s.users.unverified), to: '/control/users?filter=unverified' },
            { key: 'ws', label: 'Workspaces', detail: `${s.workspaces} created`, icon: Building2, state: on(s.workspaces), to: '/control/workspaces' },
            { key: 'biz', label: 'Businesses', detail: `${s.businesses} added`, icon: Store, state: on(s.businesses) },
            { key: 'audits', label: 'Audits', detail: `${s.crawl_runs.completed_7d} done this week`, icon: Gauge, state: s.crawl_runs.running + s.crawl_runs.queued > 0 ? 'live' : s.crawl_runs.completed_7d > 0 ? 'partial' : 'off' },
            { key: 'paying', label: 'Paying', detail: `${s.paying_workspaces} workspaces`, icon: CreditCard, state: on(s.paying_workspaces), to: '/control/payments' },
          ]}
        />
      </section>
      <div className="grid">
        <Stat label="People" value={s.users.total} hint={<>{s.users.new_7d} new this week · {s.users.active_7d} signed in this week</>} to="/control/users" />
        <Stat label="Paying workspaces" value={s.paying_workspaces} hint={<>of {s.workspaces} workspaces</>} to="/control/workspaces" />
        <Stat label="Monthly recurring revenue" value={naira(s.mrr)} hint={<>{naira(s.revenue_30d)} received in 30 days</>} to="/control/payments" />
        <Stat label="Businesses" value={s.businesses} hint={<>{s.businesses_without_website} without a website</>} />
        <Stat label="Need a nudge" value={s.users.unverified} hint="email not confirmed yet" to="/control/users?filter=unverified" />
        <Stat label="Failed background jobs" value={s.failed_jobs} hint={s.failed_jobs ? 'look at these' : 'all clear'} to="/control/jobs" />
        <Stat label="Audits running / queued" value={`${s.crawl_runs.running} / ${s.crawl_runs.queued}`} hint={<>{s.crawl_runs.completed_7d} done, {s.crawl_runs.failed_7d} failed this week</>} />
        <Stat label="Provider spend (30 days)" value={`$${s.ai_spend_30d_usd.toFixed(2)}`} hint="DataForSEO and AI, estimated" />
      </div>
      {trends.data && <SignupChart rows={trends.data.data} />}
      <div className="grid">
        <div className="card stack" style={{ gap: '0.5rem' }}>
          <h2 style={{ margin: 0 }}>Plans</h2>
          {Object.entries(s.plans).map(([p, n]) => <div key={p} className="row between"><span>{humanize(p)}</span><strong>{n}</strong></div>)}
        </div>
        <div className="card stack" style={{ gap: '0.5rem' }}>
          <h2 style={{ margin: 0 }}>Connected accounts</h2>
          {s.connections.length === 0 && <p className="small muted" style={{ margin: 0 }}>None yet.</p>}
          {s.connections.map((c) => <div key={c.provider + c.status} className="row between"><span>{humanize(c.provider)} <span className="small muted">({humanize(c.status)})</span></span><strong>{c.n}</strong></div>)}
          <div className="row between"><span>WordPress sites</span><strong>{s.website_connectors}</strong></div>
        </div>
      </div>
      <div className="card stack">
        <h2 style={{ margin: 0 }}>Recent failed audits</h2>
        {runs.data?.data.length ? (
          <div className="table-wrap"><table>
            <thead><tr><th>When</th><th>Website</th><th>Error</th><th /></tr></thead>
            <tbody>{runs.data.data.slice(0, 10).map((r) => (
              <tr key={r.id}><td className="small">{when(r.created_at)}</td><td style={{ wordBreak: 'break-all' }}>{r.start_url}</td><td className="small">{r.error}</td><td><Link to={`/control/workspaces/${r.workspace_id}`}>Open</Link></td></tr>
            ))}</tbody>
          </table></div>
        ) : <p className="small muted" style={{ margin: 0 }}>None. Good.</p>}
      </div>
    </div>
  )
}

function Users() {
  const params = new URLSearchParams(window.location.search)
  const [search, setSearch] = useState('')
  const [q, setQ] = useState('')
  const [filter, setFilter] = useState(params.get('filter') ?? '')
  const [page, setPage] = useState(1)
  const list = useQuery({ queryKey: ['admin', 'users', q, filter, page], queryFn: () => api<Page<UserRow>>(`/admin/users?search=${encodeURIComponent(q)}&filter=${filter}&page=${page}`) })
  return (
    <div className="stack">
      <PageTitle title="People" sub="Everyone who has signed up. Search by name or email, resend confirmation, reset access or suspend an account." />
      <form className="row" onSubmit={(e) => { e.preventDefault(); setQ(search); setPage(1) }}>
        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search name or email" aria-label="Search people" style={{ maxWidth: 320 }} />
        <select value={filter} onChange={(e) => { setFilter(e.target.value); setPage(1) }} aria-label="Filter" style={{ maxWidth: 220 }}>
          <option value="">Everyone</option><option value="unverified">Email not confirmed</option><option value="no_mfa">No two-step sign-in</option>
          <option value="disabled">Suspended</option><option value="staff">Findriva staff</option>
        </select>
        <button className="btn sm">Search</button>
      </form>
      {list.isLoading && <Spinner />}
      {list.error && <ErrorState error={list.error} />}
      {list.data && (list.data.data.length === 0 ? <Empty title="No one matches" /> : (
        <div className="card table-wrap">
          <table>
            <thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Workspaces</th><th>Last sign-in</th><th>Joined</th></tr></thead>
            <tbody>{list.data.data.map((u) => (
              <tr key={u.id}>
                <td><Link to={`/control/users/${u.id}`}>{u.name}</Link></td>
                <td style={{ wordBreak: 'break-all' }}>{u.email}</td>
                <td><UserBadges u={u} /></td>
                <td>{u.workspaces_count}</td>
                <td className="small">{when(u.last_login_at)}</td>
                <td className="small">{day(u.created_at)}</td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      ))}
      <Pager page={list.data} set={setPage} />
    </div>
  )
}

function UserBadges({ u }: { u: UserRow }) {
  return (
    <span className="row" style={{ gap: '0.3rem' }}>
      {u.disabled_at && <Badge tone="critical">Suspended</Badge>}
      {u.is_platform_admin && <Badge tone="brand">Staff</Badge>}
      {u.email_verified ? <Badge tone="ok">Confirmed</Badge> : <Badge tone="medium">Unconfirmed</Badge>}
      {u.mfa_enabled && <Badge>2-step</Badge>}
    </span>
  )
}

function UserDetail() {
  const { id } = useParams()
  const qc = useQueryClient()
  const key = ['admin', 'user', id]
  const q = useQuery({ queryKey: key, queryFn: () => api<{ data: UserRow & { workspaces: { id: number; name: string; plan: string; billing_status: string; role: string }[]; activity: LogRow[]; notes: Note[] } }>(`/admin/users/${id}`) })
  const refresh = () => qc.invalidateQueries({ queryKey: key })
  const [msg, setMsg] = useState<string | null>(null)
  const [reason, setReason] = useState('')
  const act = useMutation({
    mutationFn: ({ path, json }: { path: string; json?: unknown }) => api<{ message?: string }>(`/admin/users/${id}/${path}`, { method: 'POST', json }),
    onSuccess: (r) => { setMsg(r?.message ?? 'Done.'); setReason(''); refresh() },
  })
  if (q.isLoading) return <Spinner />
  if (q.error) return <ErrorState error={q.error} />
  const u = q.data!.data
  const err = fieldErrors(act.error).user?.[0] ?? fieldErrors(act.error).reason?.[0] ?? (act.error instanceof Error ? act.error.message : null)
  return (
    <div className="stack">
      <div><Link to="/control/users" className="small">← People</Link><h2 style={{ margin: '0.25rem 0' }}>{u.name}</h2><div className="row small muted"><span>{u.email}</span><UserBadges u={u} /></div></div>
      <div className="grid">
        <Stat label="Joined" value={day(u.created_at)} />
        <Stat label="Last sign-in" value={u.last_login_at ? day(u.last_login_at) : 'Never'} />
        <Stat label="Two-step sign-in" value={u.mfa_enabled ? 'On' : 'Off'} />
      </div>
      <div className="card stack">
        <h2 style={{ margin: 0 }}>Help this person</h2>
        <p className="small muted" style={{ margin: 0 }}>These send Findriva's normal emails to {u.email}. Staff never see or set a password.</p>
        <div className="row" style={{ flexWrap: 'wrap' }}>
          {!u.email_verified && <button className="btn sm" disabled={act.isPending} onClick={() => act.mutate({ path: 'resend-verification' })}>Resend confirmation email</button>}
          <button className="btn sm" disabled={act.isPending} onClick={() => act.mutate({ path: 'password-reset' })}>Send password reset link</button>
        </div>
        {u.disabled_at ? (
          <div className="notice warn stack" style={{ gap: '0.5rem' }}>
            <div>Suspended {when(u.disabled_at)}: {u.disabled_reason}</div>
            <div><button className="btn primary sm" onClick={() => act.mutate({ path: 'enable' })}>Lift suspension</button></div>
          </div>
        ) : !u.is_platform_admin && (
          <form className="row" onSubmit={(e) => { e.preventDefault(); act.mutate({ path: 'disable', json: { reason } }) }}>
            <input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Reason (for the record)" aria-label="Suspension reason" style={{ maxWidth: 360 }} />
            <button className="btn ghost sm danger" disabled={!reason.trim() || act.isPending}>Suspend and sign out</button>
          </form>
        )}
        {msg && <p className="notice" style={{ margin: 0 }}>{msg}</p>}
        {err && <p className="error-text">{err}</p>}
      </div>
      <div className="card stack">
        <h2 style={{ margin: 0 }}>Workspaces</h2>
        {u.workspaces.length === 0 ? <p className="small muted" style={{ margin: 0 }}>None.</p> : u.workspaces.map((w) => (
          <div key={w.id} className="row between"><Link to={`/control/workspaces/${w.id}`}>{w.name}</Link><span className="row small"><Badge>{humanize(w.role)}</Badge><Badge tone="brand">{humanize(w.plan)}</Badge><span className="muted">{humanize(w.billing_status)}</span></span></div>
        ))}
      </div>
      <Notes notes={u.notes} userId={u.id} onChange={refresh} />
      <div className="card stack"><h2 style={{ margin: 0 }}>What they did recently</h2><Activity rows={u.activity} showActor={false} /></div>
    </div>
  )
}

function Workspaces() {
  const [search, setSearch] = useState('')
  const [q, setQ] = useState('')
  const [plan, setPlan] = useState('')
  const [page, setPage] = useState(1)
  const list = useQuery({ queryKey: ['admin', 'workspaces', q, plan, page], queryFn: () => api<Page<WorkspaceRow>>(`/admin/workspaces?search=${encodeURIComponent(q)}&plan=${plan}&page=${page}`) })
  return (
    <div className="stack">
      <PageTitle title="Workspaces" sub="Every customer workspace with its plan and billing state. Search by name, number or member email." />
      <form className="row" onSubmit={(e) => { e.preventDefault(); setQ(search); setPage(1) }}>
        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Workspace name, number or a member's email" aria-label="Search workspaces" style={{ maxWidth: 340 }} />
        <select value={plan} onChange={(e) => { setPlan(e.target.value); setPage(1) }} aria-label="Plan" style={{ maxWidth: 180 }}>
          <option value="">All plans</option>{['free', 'starter', 'growth', 'agency'].map((p) => <option key={p} value={p}>{humanize(p)}</option>)}
        </select>
        <button className="btn sm">Search</button>
      </form>
      {list.isLoading && <Spinner />}
      {list.error && <ErrorState error={list.error} />}
      {list.data && (list.data.data.length === 0 ? <Empty title="No workspaces match" /> : (
        <div className="card table-wrap">
          <table>
            <thead><tr><th>#</th><th>Name</th><th>Plan</th><th>Billing</th><th>Businesses</th><th>People</th><th>Created</th></tr></thead>
            <tbody>{list.data.data.map((w) => (
              <tr key={w.id}><td>{w.id}</td><td><Link to={`/control/workspaces/${w.id}`}>{w.name}</Link></td><td>{humanize(w.plan)}</td><td>{humanize(w.billing_status)}</td><td>{w.businesses_count}</td><td>{w.memberships_count}</td><td className="small">{day(w.created_at)}</td></tr>
            ))}</tbody>
          </table>
        </div>
      ))}
      <Pager page={list.data} set={setPage} />
    </div>
  )
}

interface WsDetail {
  workspace: { id: number; name: string; slug: string; type: string; plan: string; billing_status: string; billing_provider: string | null; cancel_at_period_end: boolean; current_period_end: string | null; created_at: string }
  members: { id: number; name: string; email: string; role: string; email_verified_at: string | null; mfa_enabled_at: string | null; last_login_at: string | null; disabled_at: string | null }[]
  businesses: {
    id: number; name: string; primary_category: string | null; website_url: string | null; open_actions: number
    last_audit: { id: number; status: string; pages_checked: number; issues_found: number; error: string | null; created_at: string } | null
    connections: { provider: string; status: string; property: string | null; last_synced_at: string | null; last_error: string | null }[]
    website_connector: { platform: string; status: string; site_url: string | null; last_seen_at: string | null; published_at: string | null } | null
  }[]
  usage_this_month: { provider: string; action: string; units: number; cost_usd: number }[]
  payments: { id: number; reference: string; plan: string; amount: number; status: string; paid_at: string | null; created_at: string }[]
  activity: LogRow[]; notes: Note[]
}

function WorkspaceDetail() {
  const { id } = useParams()
  const qc = useQueryClient()
  const key = ['admin', 'workspace', id]
  const q = useQuery({ queryKey: key, queryFn: () => api<{ data: WsDetail }>(`/admin/workspaces/${id}`) })
  const refresh = () => qc.invalidateQueries({ queryKey: key })
  const [msg, setMsg] = useState<string | null>(null)
  const update = useMutation({ mutationFn: (json: Record<string, unknown>) => api(`/admin/workspaces/${id}`, { method: 'PATCH', json }), onSuccess: () => { setMsg('Saved.'); refresh() } })
  const audit = useMutation({ mutationFn: (bid: number) => api(`/admin/workspaces/${id}/businesses/${bid}/audit`, { method: 'POST' }), onSuccess: () => { setMsg('Audit started.'); refresh() } })
  const [until, setUntil] = useState('')
  if (q.isLoading) return <Spinner />
  if (q.error) return <ErrorState error={q.error} />
  const d = q.data!.data
  const w = d.workspace
  const failure = update.error ?? audit.error
  const err = Object.values(fieldErrors(failure))[0]?.[0] ?? (failure instanceof Error ? failure.message : null)
  return (
    <div className="stack">
      <div><Link to="/control/workspaces" className="small">← Workspaces</Link><h2 style={{ margin: '0.25rem 0' }}>{w.name}</h2><div className="small muted">#{w.id} · {humanize(w.type)} · created {day(w.created_at)}</div></div>

      <div className="card stack">
        <h2 style={{ margin: 0 }}>Plan and billing</h2>
        <div className="row" style={{ flexWrap: 'wrap' }}>
          <label className="small">Plan <select value={w.plan} onChange={(e) => update.mutate({ plan: e.target.value })}>{['free', 'starter', 'growth', 'agency'].map((p) => <option key={p} value={p}>{humanize(p)}</option>)}</select></label>
          <label className="small">Billing status <select value={w.billing_status} onChange={(e) => update.mutate({ billing_status: e.target.value })}>{['trial', 'active', 'past_due', 'cancelled'].map((p) => <option key={p} value={p}>{humanize(p)}</option>)}</select></label>
        </div>
        <div className="small">{w.current_period_end ? <>Paid access until <strong>{day(w.current_period_end)}</strong>{w.cancel_at_period_end ? ' (will not renew)' : ''}.</> : 'No paid period.'} {w.billing_provider && <span className="muted">Billed through {humanize(w.billing_provider)}.</span>}</div>
        <form className="row" onSubmit={(e) => { e.preventDefault(); if (until) update.mutate({ current_period_end: until }) }}>
          <label className="small">Give access until <input type="date" value={until} onChange={(e) => setUntil(e.target.value)} style={{ maxWidth: 180 }} /></label>
          <button className="btn sm" disabled={!until}>Save date</button>
        </form>
        <p className="small muted" style={{ margin: 0 }}>Changes here do not charge or refund anyone. Refunds are done in Paystack.</p>
        {msg && <p className="notice" style={{ margin: 0 }}>{msg}</p>}
        {err && <p className="error-text">{err}</p>}
      </div>

      <div className="card stack">
        <h2 style={{ margin: 0 }}>People</h2>
        <div className="table-wrap"><table>
          <thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Last sign-in</th></tr></thead>
          <tbody>{d.members.map((m) => (
            <tr key={m.id}><td><Link to={`/control/users/${m.id}`}>{m.name}</Link><div className="small muted" style={{ wordBreak: 'break-all' }}>{m.email}</div></td><td>{humanize(m.role)}</td>
              <td><span className="row" style={{ gap: '0.3rem' }}>{m.disabled_at && <Badge tone="critical">Suspended</Badge>}{m.email_verified_at ? <Badge tone="ok">Confirmed</Badge> : <Badge tone="medium">Unconfirmed</Badge>}{m.mfa_enabled_at && <Badge>2-step</Badge>}</span></td>
              <td className="small">{when(m.last_login_at)}</td></tr>
          ))}</tbody>
        </table></div>
      </div>

      <div className="stack">
        <h2 style={{ margin: 0 }}>Businesses</h2>
        {d.businesses.length === 0 && <Empty title="No businesses yet">They signed up but have not added a business. A friendly email could help.</Empty>}
        {d.businesses.map((b) => (
          <div key={b.id} className="card stack" style={{ gap: '0.6rem' }}>
            <div className="row between">
              <div><strong>{b.name}</strong> <span className="small muted">{b.primary_category}</span><div className="small">{b.website_url ? <a href={b.website_url} target="_blank" rel="noreferrer noopener">{b.website_url}</a> : <span className="muted">No website</span>}</div></div>
              <Badge>{b.open_actions} open actions</Badge>
            </div>
            <div className="row small" style={{ flexWrap: 'wrap' }}>
              <span>Last audit:</span>
              {b.last_audit ? <><StatusBadge status={b.last_audit.status} /><span className="muted">{when(b.last_audit.created_at)} · {b.last_audit.pages_checked} pages · {b.last_audit.issues_found} issues</span></> : <span className="muted">none</span>}
              {b.website_url && <button className="btn ghost sm" disabled={audit.isPending} onClick={() => audit.mutate(b.id)}>Run a new audit</button>}
            </div>
            {b.last_audit?.error && <p className="notice warn small" style={{ margin: 0 }}>{b.last_audit.error}</p>}
            <div className="row small" style={{ flexWrap: 'wrap', gap: '0.4rem' }}>
              {b.connections.map((c) => <Badge key={c.provider} tone={c.status === 'connected' ? 'ok' : 'medium'}>{humanize(c.provider)}: {humanize(c.status)}</Badge>)}
              {b.website_connector && <Badge tone={b.website_connector.status === 'connected' ? 'ok' : 'medium'}>WordPress: {humanize(b.website_connector.status)}</Badge>}
              {b.connections.length === 0 && !b.website_connector && <span className="muted">No accounts connected</span>}
            </div>
            {b.connections.filter((c) => c.last_error).map((c) => <p key={c.provider} className="small error-text" style={{ margin: 0 }}>{humanize(c.provider)}: {c.last_error}</p>)}
          </div>
        ))}
      </div>

      <div className="grid">
        <div className="card stack" style={{ gap: '0.5rem' }}>
          <h2 style={{ margin: 0 }}>Usage this month</h2>
          {d.usage_this_month.length === 0 ? <p className="small muted" style={{ margin: 0 }}>Nothing yet.</p> : d.usage_this_month.map((u) => (
            <div key={u.provider + u.action} className="row between small"><span>{humanize(u.action)} <span className="muted">({u.provider})</span></span><span>{u.units}{u.cost_usd ? <span className="muted"> · ${u.cost_usd.toFixed(2)}</span> : null}</span></div>
          ))}
        </div>
        <div className="card stack" style={{ gap: '0.5rem' }}>
          <h2 style={{ margin: 0 }}>Payments</h2>
          {d.payments.length === 0 ? <p className="small muted" style={{ margin: 0 }}>No payments.</p> : d.payments.map((p) => (
            <div key={p.id} className="row between small"><span>{day(p.paid_at ?? p.created_at)} · {humanize(p.plan)}</span><span>{naira(p.amount)} <Badge tone={p.status === 'success' ? 'ok' : p.status === 'failed' ? 'critical' : ''}>{humanize(p.status)}</Badge></span></div>
          ))}
        </div>
      </div>

      <Notes notes={d.notes} workspaceId={w.id} onChange={refresh} />
      <div className="card stack"><h2 style={{ margin: 0 }}>Activity</h2><Activity rows={d.activity} /></div>
    </div>
  )
}

function Payments() {
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const list = useQuery({ queryKey: ['admin', 'payments', status, page], queryFn: () => api<Page<Payment>>(`/admin/payments?status=${status}&page=${page}`) })
  return (
    <div className="stack">
      <PageTitle title="Payments" sub="Every Paystack payment attempt, newest first." />
      <div className="row">
        <select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }} aria-label="Payment status" style={{ maxWidth: 200 }}>
          <option value="">All payments</option><option value="success">Successful</option><option value="failed">Failed</option><option value="pending">Pending</option><option value="abandoned">Abandoned</option>
        </select>
      </div>
      {list.isLoading && <Spinner />}
      {list.data && (list.data.data.length === 0 ? <Empty title="No payments yet" /> : (
        <div className="card table-wrap"><table>
          <thead><tr><th>When</th><th>Workspace</th><th>Plan</th><th>Amount</th><th>Status</th><th>Paystack reference</th></tr></thead>
          <tbody>{list.data.data.map((p) => (
            <tr key={p.id}><td className="small">{when(p.paid_at ?? p.created_at)}</td><td><Link to={`/control/workspaces/${p.workspace_id}`}>{p.workspace_name ?? `#${p.workspace_id}`}</Link></td><td>{humanize(p.plan)}</td><td>{naira(p.amount)}</td>
              <td><Badge tone={p.status === 'success' ? 'ok' : p.status === 'failed' ? 'critical' : ''}>{humanize(p.status)}</Badge></td><td className="small"><code>{p.reference}</code></td></tr>
          ))}</tbody>
        </table></div>
      ))}
      <Pager page={list.data} set={setPage} />
    </div>
  )
}

function Jobs() {
  const qc = useQueryClient()
  const list = useQuery({ queryKey: ['admin', 'jobs'], queryFn: () => api<{ data: FailedJob[]; total: number }>('/admin/failed-jobs') })
  const refresh = () => { qc.invalidateQueries({ queryKey: ['admin', 'jobs'] }); qc.invalidateQueries({ queryKey: ['admin', 'stats'] }) }
  const retry = useMutation({ mutationFn: (uuid: string) => api(`/admin/failed-jobs/${uuid}/retry`, { method: 'POST' }), onSuccess: refresh })
  const forget = useMutation({ mutationFn: (uuid: string) => api(`/admin/failed-jobs/${uuid}`, { method: 'DELETE' }), onSuccess: refresh })
  if (list.isLoading) return <Spinner />
  if (list.error) return <ErrorState error={list.error} />
  const jobs = list.data!.data
  return (
    <div className="stack">
      <PageTitle title="Failed jobs" />
      <p className="small muted" style={{ margin: 0 }}>Background work that stopped with an error: audits, imports from Google and Bing, rank checks and emails. Retry once the cause is fixed; remove ones that no longer matter.</p>
      {jobs.length === 0 ? <Empty title="No failed jobs">Everything in the background is running.</Empty> : (
        <div className="card table-wrap"><table>
          <thead><tr><th>When</th><th>Job</th><th>Error</th><th /></tr></thead>
          <tbody>{jobs.map((j) => (
            <tr key={j.uuid}><td className="small">{when(j.failed_at)}</td><td>{j.job}<div className="small muted">{j.queue}</div></td><td className="small" style={{ wordBreak: 'break-word' }}>{j.error}</td>
              <td><span className="row" style={{ flexWrap: 'nowrap' }}><button className="btn sm" disabled={retry.isPending} onClick={() => retry.mutate(j.uuid)}>Retry</button><button className="btn ghost sm" disabled={forget.isPending} onClick={() => forget.mutate(j.uuid)}>Remove</button></span></td></tr>
          ))}</tbody>
        </table></div>
      )}
    </div>
  )
}

function Log() {
  const [action, setAction] = useState('')
  const [page, setPage] = useState(1)
  const list = useQuery({ queryKey: ['admin', 'logs', action, page], queryFn: () => api<Page<LogRow>>(`/admin/audit-logs?action=${encodeURIComponent(action)}&page=${page}`) })
  return (
    <div className="stack">
      <PageTitle title="Activity log" sub="Everything staff and customers changed, newest first." />
      <div className="row">
        <select value={action} onChange={(e) => { setAction(e.target.value); setPage(1) }} aria-label="Kind of activity" style={{ maxWidth: 240 }}>
          <option value="">Everything</option><option value="auth.">Sign-ins and accounts</option><option value="admin.">Staff actions</option><option value="billing.">Billing</option>
          <option value="integration">Connected accounts</option><option value="wordpress.">WordPress</option><option value="review">Reviews</option>
        </select>
      </div>
      {list.isLoading && <Spinner />}
      {list.data && <div className="card"><Activity rows={list.data.data} /></div>}
      <Pager page={list.data} set={setPage} />
    </div>
  )
}

/** Platform Control pages. The shell, guard and navigation live in layouts/ControlShell. */
export default function AdminRoutes() {
  return (
    <Routes>
      <Route index element={<Overview />} />
      <Route path="users" element={<Users />} />
      <Route path="users/:id" element={<UserDetail />} />
      <Route path="workspaces" element={<Workspaces />} />
      <Route path="workspaces/:id" element={<WorkspaceDetail />} />
      <Route path="payments" element={<Payments />} />
      <Route path="jobs" element={<Jobs />} />
      <Route path="activity" element={<Log />} />
    </Routes>
  )
}
