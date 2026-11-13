import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type Business } from '../../api'
import { useAuth, useRole } from '../../auth'
import { Badge, Empty, ErrorState, Spinner, fieldErrors, humanize } from '../../ui'

interface Connection {
  id: number; status: string; account_email: string | null; property: string | null
  available_properties: { site_url: string; permission: string }[]; last_synced_at: string | null; data_through: string | null; last_error: string | null
}
interface Integrations {
  google_search_console: { available: boolean; connection: Connection | null }
  indexnow: null | { host: string; key: string | null; key_location: string | null; verified_at: string | null; recent: { id: number; url_count: number; outcome: string; message: string | null; created_at: string }[] }
  domain: { domain: string; verification_status: string; verification_method: string | null } | null
  can_manage: boolean
}
interface Totals { clicks: number; impressions: number; ctr: number | null; position: number | null; days: number }
interface Row { clicks: number; impressions: number; position: number; query?: string; page?: string }
interface Performance {
  connected: boolean; measured: boolean; status?: string; source?: string; property?: string
  period?: { start: string; end: string; days: number }; last_synced_at?: string
  totals?: Totals; previous?: Totals; top_queries?: Row[]; top_pages?: Row[]; note?: string
  tracked_keywords?: { id: number; keyword: string; seen: boolean; clicks?: number; impressions?: number; position?: number }[]
}
interface Report {
  business: { name: string }; month: string; period: { start: string; end: string }; generated_at: string
  identity: { score: number; missing: string[] }; issues: { found: number; resolved: number; open_now: number }
  actions: { completed: number; verified: number; verified_titles: string[] }; content_approved: number
  public_page: { published: boolean; url: string | null }; indexnow: { submissions: number; urls_accepted: number }
  search: null | { source: string; property: string; days_measured: number; totals: Totals; previous_month: Totals; comparable: boolean }
  not_measured: string[]
}

const ERRORS: Record<string, string> = {
  expired: 'The Google sign-in took too long or was already used. Please try again.',
  declined: 'Google access was not granted, so nothing was connected.',
  forbidden: 'Only an owner or admin can connect accounts.',
  scope: 'Google did not grant read access to Search Console. Please tick the Search Console box when asked.',
  google: 'Google returned an error while connecting. Please try again.',
}

function change(now: number | null | undefined, before: number | null | undefined, lowerIsBetter = false) {
  if (now == null || before == null || before === 0) return null
  const pct = ((now - before) / before) * 100
  const good = lowerIsBetter ? pct < 0 : pct > 0
  return <span className="small" style={{ color: Math.abs(pct) < 0.5 ? 'var(--muted)' : good ? 'var(--ok)' : 'var(--critical)' }}>{pct > 0 ? '+' : ''}{pct.toFixed(0)}% vs previous</span>
}

function Stat({ label, value, delta }: { label: string; value: string; delta?: React.ReactNode }) {
  return <div className="card stat"><div className="label">{label}</div><div className="value">{value}</div>{delta}</div>
}

function SearchConsoleCard({ business, data, onChange }: { business: Business; data: Integrations; onChange: () => void }) {
  const c = data.google_search_console.connection
  const connect = useMutation({
    mutationFn: () => api<{ data: { authorization_url: string } }>(`/businesses/${business.id}/integrations/google/connect`, { method: 'POST' }),
    onSuccess: (r) => { window.location.href = r.data.authorization_url },
  })
  const choose = useMutation({ mutationFn: (property: string) => api(`/businesses/${business.id}/integrations/${c!.id}`, { method: 'PATCH', json: { property } }), onSuccess: onChange })
  const sync = useMutation({ mutationFn: () => api(`/businesses/${business.id}/integrations/${c!.id}/sync`, { method: 'POST' }), onSuccess: onChange })
  const disconnect = useMutation({ mutationFn: () => api(`/businesses/${business.id}/integrations/${c!.id}`, { method: 'DELETE' }), onSuccess: onChange })
  const err = fieldErrors(connect.error).google?.[0] ?? (connect.error instanceof Error ? connect.error.message : null)

  return (
    <div className="card stack">
      <div className="row between">
        <h3 style={{ margin: 0 }}>Google Search Console</h3>
        {c ? <Badge tone={c.status === 'connected' ? 'ok' : 'medium'}>{humanize(c.status)}</Badge> : <Badge>Not connected</Badge>}
      </div>
      <p className="small muted" style={{ margin: 0 }}>Your real clicks, impressions and average positions on Google, read-only. You sign in with Google; Findriva never sees your password and you can remove access at any time.</p>
      {!c && (data.google_search_console.available
        ? (data.can_manage ? <div><button className="btn primary" disabled={connect.isPending} onClick={() => connect.mutate()}>Connect with Google</button></div> : <p className="small muted">Ask an owner or admin to connect it.</p>)
        : <p className="small muted">Google sign-in is not set up on this Findriva installation yet.</p>)}
      {err && <p className="error-text">{err}</p>}
      {c && (
        <div className="stack" style={{ gap: '0.5rem' }}>
          <div className="small">{c.account_email && <>Signed in as <strong>{c.account_email}</strong>. </>}{c.property ? <>Property: <strong>{c.property}</strong>. </> : null}{c.data_through ? <>Data through {c.data_through}.</> : c.property ? 'First import is running.' : null}</div>
          {c.status === 'needs_property' && (
            <label className="small">Which Search Console property is this business?
              <select defaultValue="" onChange={(e) => e.target.value && choose.mutate(e.target.value)} disabled={!data.can_manage}>
                <option value="">Choose a property</option>
                {c.available_properties.filter((p) => p.permission !== 'siteUnverifiedUser').map((p) => <option key={p.site_url} value={p.site_url}>{p.site_url}</option>)}
              </select>
            </label>
          )}
          {c.last_error && <p className="notice warn" style={{ margin: 0 }}>{c.last_error}</p>}
          <div className="row">
            {c.status === 'revoked' && data.can_manage && <button className="btn primary sm" onClick={() => connect.mutate()}>Reconnect</button>}
            {c.property && c.status !== 'revoked' && <button className="btn ghost sm" disabled={sync.isPending} onClick={() => sync.mutate()}>{sync.isSuccess ? 'Import queued' : 'Import latest data'}</button>}
            {data.can_manage && <button className="btn ghost sm danger" disabled={disconnect.isPending} onClick={() => disconnect.mutate()}>Disconnect and delete imported data</button>}
          </div>
        </div>
      )}
    </div>
  )
}

function IndexNowCard({ business, data, onChange }: { business: Business; data: Integrations; onChange: () => void }) {
  const ix = data.indexnow
  const makeKey = useMutation({ mutationFn: () => api(`/businesses/${business.id}/indexnow/key`, { method: 'POST' }), onSuccess: onChange })
  const verify = useMutation({ mutationFn: () => api(`/businesses/${business.id}/indexnow/verify`, { method: 'POST' }), onSettled: onChange })
  const submit = useMutation({ mutationFn: () => api(`/businesses/${business.id}/indexnow/submit`, { method: 'POST' }), onSettled: onChange })
  const { canEdit } = useRole()
  if (!ix) return null
  return (
    <div className="card stack">
      <div className="row between">
        <h3 style={{ margin: 0 }}>IndexNow (Bing and others)</h3>
        {ix.verified_at ? <Badge tone="ok">Key confirmed</Badge> : <Badge>Not set up</Badge>}
      </div>
      <p className="small muted" style={{ margin: 0 }}>Tells Bing, Yandex, Naver and Seznam when your pages change so they can re-read them sooner. It asks them to crawl; it cannot guarantee indexing.</p>
      {!ix.key && canEdit && <div><button className="btn ghost" onClick={() => makeKey.mutate()}>Create my key file</button></div>}
      {ix.key && (
        <ol className="small" style={{ margin: 0, paddingLeft: '1.2rem' }}>
          <li>Create a text file named <code>{ix.key}.txt</code> containing only <code>{ix.key}</code>.</li>
          <li>Upload it so it opens at <a href={ix.key_location!} target="_blank" rel="noreferrer noopener">{ix.key_location}</a>.</li>
          <li>Press “Check key file”.</li>
        </ol>
      )}
      {verify.isError && <p className="error-text">We could not find the key file at that address yet.</p>}
      {submit.error && <p className="error-text">{fieldErrors(submit.error).indexnow?.[0] ?? fieldErrors(submit.error).urls?.[0] ?? (submit.error as Error).message}</p>}
      <div className="row">
        {ix.key && canEdit && <button className="btn ghost sm" disabled={verify.isPending} onClick={() => verify.mutate()}>Check key file</button>}
        {ix.verified_at && data.can_manage && <button className="btn primary sm" disabled={submit.isPending} onClick={() => submit.mutate()}>Submit my pages</button>}
      </div>
      {ix.recent.length > 0 && (
        <ul className="small" style={{ margin: 0, paddingLeft: '1.2rem' }}>
          {ix.recent.map((s) => <li key={s.id}>{new Date(s.created_at).toLocaleDateString()}: {s.url_count} page{s.url_count === 1 ? '' : 's'}, {humanize(s.outcome)}. <span className="muted">{s.message}</span></li>)}
        </ul>
      )}
    </div>
  )
}

function ReportView({ business }: { business: Business }) {
  const { workspaceId } = useAuth()
  const [month, setMonth] = useState(() => new Date().toISOString().slice(0, 7))
  const q = useQuery({ queryKey: ['report', workspaceId, business.id, month], queryFn: () => api<{ data: Report }>(`/businesses/${business.id}/report?month=${month}`) })
  const r = q.data?.data
  const fmt = (n: number | null | undefined) => (n == null ? 'Not measured' : n.toLocaleString())
  return (
    <div className="card stack report">
      <div className="row between no-print">
        <h3 style={{ margin: 0 }}>Monthly report</h3>
        <div className="row">
          <input type="month" value={month} onChange={(e) => e.target.value && setMonth(e.target.value)} aria-label="Report month" style={{ width: 'auto' }} />
          <button className="btn ghost sm" onClick={() => window.print()}>Print or save as PDF</button>
        </div>
      </div>
      {q.isLoading && <Spinner />}
      {q.error && <ErrorState error={q.error} />}
      {r && (
        <>
          <div>
            <h2 style={{ margin: 0 }}>{r.business.name}: {new Date(r.period.start + 'T00:00:00').toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}</h2>
            <p className="small muted" style={{ margin: 0 }}>Generated {new Date(r.generated_at).toLocaleString()}. Every number below is counted or measured; nothing is estimated.</p>
          </div>
          <table>
            <tbody>
              <tr><th>Profile completeness</th><td>{r.identity.score}/100{r.identity.missing.length > 0 && <span className="small muted"> (missing: {r.identity.missing.map(humanize).join(', ')})</span>}</td></tr>
              <tr><th>Issues found this month</th><td>{r.issues.found}</td></tr>
              <tr><th>Issues confirmed fixed</th><td>{r.issues.resolved}</td></tr>
              <tr><th>Issues still open</th><td>{r.issues.open_now}</td></tr>
              <tr><th>Actions completed / verified</th><td>{r.actions.completed} / {r.actions.verified}</td></tr>
              <tr><th>Content approved</th><td>{r.content_approved}</td></tr>
              <tr><th>Public page</th><td>{r.public_page.published ? <a href={r.public_page.url!}>{r.public_page.url}</a> : 'Not published'}</td></tr>
              <tr><th>Pages sent to IndexNow</th><td>{r.indexnow.urls_accepted} accepted in {r.indexnow.submissions} submission{r.indexnow.submissions === 1 ? '' : 's'}</td></tr>
              {r.search && <>
                <tr><th>Google clicks</th><td>{fmt(r.search.totals.clicks)} {r.search.comparable && change(r.search.totals.clicks, r.search.previous_month.clicks)}</td></tr>
                <tr><th>Google impressions</th><td>{fmt(r.search.totals.impressions)} {r.search.comparable && change(r.search.totals.impressions, r.search.previous_month.impressions)}</td></tr>
                <tr><th>Average position</th><td>{r.search.totals.position ?? 'Not measured'}</td></tr>
                <tr><th>Source</th><td className="small">{r.search.source}, {r.search.property}, {r.search.days_measured} days measured{!r.search.comparable && r.search.previous_month.days > 0 ? '. The month is not complete yet, so it is not compared with last month.' : ''}</td></tr>
              </>}
            </tbody>
          </table>
          {r.actions.verified_titles.length > 0 && <div><strong className="small">Verified fixes</strong><ul className="small">{r.actions.verified_titles.map((t) => <li key={t}>{t}</li>)}</ul></div>}
          {r.not_measured.length > 0 && <p className="small muted" style={{ margin: 0 }}>Not measured: {r.not_measured.join('; ')}.</p>}
        </>
      )}
    </div>
  )
}

export default function ResultsTab({ business }: { business: Business }) {
  const { workspaceId } = useAuth()
  const qc = useQueryClient()
  const [params] = useSearchParams()
  const [days, setDays] = useState(28)
  const ik = ['integrations', workspaceId, business.id]
  const integrations = useQuery({ queryKey: ik, queryFn: () => api<{ data: Integrations }>(`/businesses/${business.id}/integrations`) })
  const perf = useQuery({
    queryKey: ['performance', workspaceId, business.id, days],
    queryFn: () => api<{ data: Performance }>(`/businesses/${business.id}/performance?days=${days}`),
    refetchInterval: (query) => (query.state.data?.data.connected && !query.state.data.data.measured ? 5000 : false),
  })
  const refresh = () => { void qc.invalidateQueries({ queryKey: ik }); void qc.invalidateQueries({ queryKey: ['performance', workspaceId, business.id] }) }
  const notice = params.get('connected') ? 'Google Search Console is connected. Your first import is running.' : null
  const error = params.get('integration_error')

  if (integrations.isLoading) return <Spinner />
  if (integrations.error) return <ErrorState error={integrations.error} retry={() => void integrations.refetch()} />
  const p = perf.data?.data

  return (
    <div className="stack">
      <p className="muted no-print">Results from your own accounts. We only show numbers that were measured, and say where each one came from.</p>
      {notice && <p className="notice no-print">{notice}</p>}
      {error && <p className="notice warn no-print">{ERRORS[error] ?? 'Connecting did not finish. Please try again.'}</p>}
      <div className="grid no-print" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: '1rem' }}>
        <SearchConsoleCard business={business} data={integrations.data!.data} onChange={refresh} />
        <IndexNowCard business={business} data={integrations.data!.data} onChange={refresh} />
      </div>

      {p?.measured && p.totals && (
        <div className="stack no-print">
          <div className="row between">
            <h3 style={{ margin: 0 }}>Google search performance</h3>
            <div className="chips" role="group" aria-label="Period">
              {[7, 28, 90].map((d) => <button key={d} aria-pressed={days === d} onClick={() => setDays(d)}>{d} days</button>)}
            </div>
          </div>
          <p className="small muted" style={{ margin: 0 }}>{p.period!.start} to {p.period!.end}, from {p.source} ({p.property}).</p>
          <div className="grid" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: '0.75rem' }}>
            <Stat label="Clicks" value={p.totals.clicks.toLocaleString()} delta={change(p.totals.clicks, p.previous?.clicks)} />
            <Stat label="Impressions" value={p.totals.impressions.toLocaleString()} delta={change(p.totals.impressions, p.previous?.impressions)} />
            <Stat label="Click-through rate" value={p.totals.ctr == null ? '–' : `${(p.totals.ctr * 100).toFixed(1)}%`} />
            <Stat label="Average position" value={p.totals.position == null ? '–' : String(p.totals.position)} delta={change(p.totals.position, p.previous?.position, true)} />
          </div>
          {p.tracked_keywords && p.tracked_keywords.length > 0 && (
            <div className="card table-wrap">
              <h3>Keywords you track</h3>
              <table><thead><tr><th>Keyword</th><th>Clicks</th><th>Impressions</th><th>Avg. position</th></tr></thead>
                <tbody>{p.tracked_keywords.map((k) => <tr key={k.id}><td>{k.keyword}</td>{k.seen ? <><td>{k.clicks}</td><td>{k.impressions}</td><td>{k.position}</td></> : <td colSpan={3} className="small muted">Not seen in Google results for this period</td>}</tr>)}</tbody>
              </table>
            </div>
          )}
          <div className="grid" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))', gap: '1rem' }}>
            {(['top_queries', 'top_pages'] as const).map((key) => (
              <div key={key} className="card table-wrap">
                <h3>{key === 'top_queries' ? 'Top searches' : 'Top pages'}</h3>
                {p[key]!.length === 0 ? <p className="small muted">No rows yet.</p> : (
                  <table><thead><tr><th>{key === 'top_queries' ? 'Search' : 'Page'}</th><th>Clicks</th><th>Impr.</th><th>Pos.</th></tr></thead>
                    <tbody>{p[key]!.map((r) => <tr key={(r.query ?? r.page)!}><td style={{ wordBreak: 'break-all' }}>{r.query ?? r.page}</td><td>{r.clicks}</td><td>{r.impressions}</td><td>{r.position}</td></tr>)}</tbody>
                  </table>
                )}
              </div>
            ))}
          </div>
          <p className="small muted">{p.note}</p>
        </div>
      )}
      {p && !p.measured && p.connected && <p className="notice no-print">Importing your Search Console data. This page updates when it is ready.</p>}
      {p && !p.connected && <Empty title="No search data yet">Connect Google Search Console above to see real clicks and impressions.</Empty>}

      <ReportView business={business} />
    </div>
  )
}
