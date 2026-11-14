import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { api, type Business } from '../../api'
import { useAuth } from '../../auth'
import { Badge, fieldErrors, humanize } from '../../ui'

export interface BingConnection {
  id: number; status: string; property: string | null; available_properties: { site_url: string; verified: boolean }[]
  last_synced_at: string | null; data_through: string | null; last_error: string | null
}
interface Sums { clicks: number; impressions: number; days: number }
interface BingPerformance {
  connected: boolean; measured: boolean; source?: string; property?: string; period?: { start: string; end: string; days: number }
  totals?: Sums; previous?: Sums; top_queries?: { query: string; clicks: number; impressions: number; position: number }[]; note?: string
}

function delta(now: number, before: number | undefined) {
  if (before === undefined || before === 0) return null
  const pct = ((now - before) / before) * 100
  return <span className="small" style={{ color: Math.abs(pct) < 0.5 ? 'var(--muted)' : pct > 0 ? 'var(--ok)' : 'var(--critical)' }}>{pct > 0 ? '+' : ''}{pct.toFixed(0)}% vs previous</span>
}

export function BingCard({ business, available, connection: c, canManage, onChange }: { business: Business; available: boolean; connection: BingConnection | null; canManage: boolean; onChange: () => void }) {
  const connect = useMutation({
    mutationFn: () => api<{ data: { authorization_url: string } }>(`/businesses/${business.id}/integrations/bing/connect`, { method: 'POST' }),
    onSuccess: (r) => { window.location.href = r.data.authorization_url },
  })
  const choose = useMutation({ mutationFn: (property: string) => api(`/businesses/${business.id}/integrations/${c!.id}`, { method: 'PATCH', json: { property } }), onSuccess: onChange })
  const sync = useMutation({ mutationFn: () => api(`/businesses/${business.id}/integrations/${c!.id}/sync`, { method: 'POST' }), onSuccess: onChange })
  const disconnect = useMutation({ mutationFn: () => api(`/businesses/${business.id}/integrations/${c!.id}`, { method: 'DELETE' }), onSuccess: onChange })
  const err = fieldErrors(connect.error).bing?.[0] ?? (connect.error instanceof Error ? connect.error.message : null)
  const verified = c?.available_properties.filter((p) => p.verified) ?? []

  return (
    <div className="card stack">
      <div className="row between">
        <h3 style={{ margin: 0 }}>Bing Webmaster Tools</h3>
        {c ? <Badge tone={c.status === 'connected' ? 'ok' : 'medium'}>{humanize(c.status)}</Badge> : <Badge>Not connected</Badge>}
      </div>
      <p className="small muted" style={{ margin: 0 }}>Your clicks and searches on Bing, which also powers Yahoo and DuckDuckGo. Read-only; you sign in on Bing's own page with a Microsoft, Google or Facebook account.</p>
      {!c && (available
        ? (canManage ? <div><button className="btn primary" disabled={connect.isPending} onClick={() => connect.mutate()}>Connect with Bing</button></div> : <p className="small muted">Ask an owner or admin to connect it.</p>)
        : <p className="small muted">Bing sign-in is not set up on this Findriva installation yet.</p>)}
      {err && <p className="error-text">{err}</p>}
      {c && (
        <div className="stack" style={{ gap: '0.5rem' }}>
          <div className="small">{c.property ? <>Site: <strong>{c.property}</strong>. </> : null}{c.data_through ? <>Data through {c.data_through}.</> : c.property ? 'First import is running.' : null}</div>
          {c.status === 'needs_property' && (verified.length > 0 ? (
            <label className="small">Which Bing site is this business?
              <select defaultValue="" onChange={(e) => e.target.value && choose.mutate(e.target.value)} disabled={!canManage}>
                <option value="">Choose a site</option>
                {verified.map((p) => <option key={p.site_url} value={p.site_url}>{p.site_url}</option>)}
              </select>
            </label>
          ) : <p className="notice warn" style={{ margin: 0 }}>This Bing account has no verified site yet. Add and verify your website in Bing Webmaster Tools (it can import it from Google Search Console), then press “Import latest data”.</p>)}
          {c.last_error && <p className="notice warn" style={{ margin: 0 }}>{c.last_error}</p>}
          <div className="row" style={{ flexWrap: 'wrap' }}>
            {c.status === 'revoked' && canManage && <button className="btn primary sm" onClick={() => connect.mutate()}>Reconnect</button>}
            {c.property && c.status !== 'revoked' && <button className="btn ghost sm" disabled={sync.isPending} onClick={() => sync.mutate()}>{sync.isSuccess ? 'Import queued' : 'Import latest data'}</button>}
            {c.status === 'needs_property' && canManage && <button className="btn ghost sm" onClick={() => connect.mutate()}>Check Bing again</button>}
            {canManage && <button className="btn ghost sm danger" disabled={disconnect.isPending} onClick={() => disconnect.mutate()}>Disconnect and delete imported data</button>}
          </div>
        </div>
      )}
    </div>
  )
}

export function BingPerformanceSection({ business }: { business: Business }) {
  const { workspaceId } = useAuth()
  const [days, setDays] = useState(28)
  const q = useQuery({
    queryKey: ['bing-performance', workspaceId, business.id, days],
    queryFn: () => api<{ data: BingPerformance }>(`/businesses/${business.id}/bing-performance?days=${days}`),
    refetchInterval: (query) => (query.state.data?.data.connected && !query.state.data.data.measured ? 5000 : false),
  })
  const p = q.data?.data
  if (!p?.connected) return null
  if (!p.measured) return <p className="notice no-print">Importing your Bing data. This page updates when it is ready.</p>
  const t = p.totals!
  return (
    <div className="stack no-print">
      <div className="row between" style={{ flexWrap: 'wrap' }}>
        <h3 style={{ margin: 0 }}>Bing search performance</h3>
        <div className="chips" role="group" aria-label="Bing period">
          {[7, 28, 90].map((d) => <button key={d} aria-pressed={days === d} onClick={() => setDays(d)}>{d} days</button>)}
        </div>
      </div>
      <p className="small muted" style={{ margin: 0 }}>{p.period!.start} to {p.period!.end}, from {p.source} ({p.property}).</p>
      <div className="grid" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: '0.75rem' }}>
        <div className="card stat"><div className="label">Clicks</div><div className="value">{t.clicks.toLocaleString()}</div>{delta(t.clicks, p.previous?.clicks)}</div>
        <div className="card stat"><div className="label">Impressions</div><div className="value">{t.impressions.toLocaleString()}</div>{delta(t.impressions, p.previous?.impressions)}</div>
        <div className="card stat"><div className="label">Click-through rate</div><div className="value">{t.impressions ? `${((t.clicks / t.impressions) * 100).toFixed(1)}%` : '–'}</div></div>
      </div>
      <div className="card table-wrap">
        <h3>Top Bing searches</h3>
        {p.top_queries!.length === 0 ? <p className="small muted">No searches reported yet.</p> : (
          <table><thead><tr><th>Search</th><th>Clicks</th><th>Impr.</th><th>Avg. pos.</th></tr></thead>
            <tbody>{p.top_queries!.map((r) => <tr key={r.query}><td style={{ wordBreak: 'break-word' }}>{r.query}</td><td>{r.clicks}</td><td>{r.impressions}</td><td>{r.position}</td></tr>)}</tbody>
          </table>
        )}
      </div>
      <p className="small muted">{p.note}</p>
    </div>
  )
}
