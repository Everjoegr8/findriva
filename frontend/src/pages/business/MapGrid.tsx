import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type Business } from '../../api'
import { useAuth, useRole } from '../../auth'
import { Badge, Empty, ErrorState, Field, Spinner, fieldErrors, humanize } from '../../ui'

interface Place { position: number; title: string; domain: string | null; rating: number | null; votes: number | null; you: boolean }
interface Point { row: number; col: number; lat: number; lng: number; position: number | null; top: Place[] }
interface Scan {
  id: number; keyword: string; status: string; size: number; spacing_km: number; depth: number
  points_checked: number; points_found: number; average_position: number | null; top_3_points: number
  error: string | null; created_at: string; completed_at: string | null
  points?: Point[]; leaders?: { title: string; domain: string | null; rating: number | null; votes: number | null; points_in_top_3: number }[]
}
interface GridResponse {
  data: Scan[]
  locations: { id: number; label: string | null; city: string | null; latitude: string; longitude: string; is_primary: boolean }[]
  keywords: { id: number; keyword: string }[]
  provider: { name: string; configured: boolean }
  allowance: { points_per_month: number; used_this_month: number }
}

function tone(p: number | null) {
  if (p === null) return { bg: 'var(--surface-2)', fg: 'var(--muted)', label: '–' }
  if (p <= 3) return { bg: 'var(--ok-soft)', fg: 'var(--ok)', label: String(p) }
  if (p <= 10) return { bg: 'var(--medium-soft)', fg: 'var(--medium)', label: String(p) }
  return { bg: 'var(--high-soft)', fg: 'var(--high)', label: String(p) }
}

function Grid({ scan, selected, onSelect }: { scan: Scan; selected: number | null; onSelect: (i: number) => void }) {
  const pts = scan.points ?? []
  return (
    <div role="grid" aria-label={`Map positions for ${scan.keyword}`}
      style={{ display: 'grid', gridTemplateColumns: `repeat(${scan.size}, minmax(0, 64px))`, gap: 6, justifyContent: 'center' }}>
      {Array.from({ length: scan.size * scan.size }, (_, i) => {
        const p = pts[i]
        const t = tone(p ? p.position : null)
        const centre = i === Math.floor((scan.size * scan.size) / 2)
        return (
          <button key={i} role="gridcell" disabled={!p} onClick={() => onSelect(i)}
            aria-label={p ? `Row ${p.row + 1}, column ${p.col + 1}: ${p.position ? `position ${p.position}` : `not in top ${scan.depth}`}` : 'Not checked'}
            style={{
              aspectRatio: '1', borderRadius: '50%', border: selected === i ? '3px solid var(--brand)' : centre ? '2px dashed var(--brand)' : '1px solid var(--border)',
              background: t.bg, color: t.fg, fontWeight: 700, fontSize: '1.05rem', cursor: p ? 'pointer' : 'default', padding: 0,
            }}>
            {p ? t.label : '·'}
          </button>
        )
      })}
    </div>
  )
}

export default function MapGridTab({ business }: { business: Business }) {
  const { workspaceId } = useAuth()
  const { canEdit } = useRole()
  const qc = useQueryClient()
  const key = ['local-grid', workspaceId, business.id]
  const q = useQuery({
    queryKey: key,
    queryFn: () => api<GridResponse>(`/businesses/${business.id}/local-grid`),
    refetchInterval: (query) => (query.state.data?.data.some((s) => ['queued', 'running'].includes(s.status)) ? 3000 : false),
  })
  const [scanId, setScanId] = useState<number | null>(null)
  const [point, setPoint] = useState<number | null>(null)
  const [keyword, setKeyword] = useState('')
  const [locationId, setLocationId] = useState<string>('')
  const [size, setSize] = useState(3)
  const [spacing, setSpacing] = useState(1)

  const d = q.data
  const currentId = scanId ?? d?.data.find((s) => ['completed', 'partial'].includes(s.status))?.id ?? null
  const detail = useQuery({
    queryKey: ['local-grid-scan', workspaceId, business.id, currentId],
    queryFn: () => api<{ data: Scan }>(`/businesses/${business.id}/local-grid/${currentId}`),
    enabled: currentId !== null,
  })
  useEffect(() => { setPoint(null) }, [currentId])
  const start = useMutation({
    mutationFn: () => {
      const kw = d?.keywords.find((k) => k.keyword === keyword)
      return api<{ data: Scan }>(`/businesses/${business.id}/local-grid`, {
        method: 'POST',
        json: { ...(kw ? { keyword_id: kw.id } : { keyword }), location_id: Number(locationId || d?.locations[0]?.id), size, spacing_km: spacing },
      })
    },
    onSuccess: (r) => { setScanId(r.data.id); void qc.invalidateQueries({ queryKey: key }) },
  })
  const running = d?.data.find((s) => ['queued', 'running'].includes(s.status))
  useEffect(() => { if (!running && scanId) void qc.invalidateQueries({ queryKey: ['local-grid-scan', workspaceId, business.id, scanId] }) }, [running, scanId, qc, workspaceId, business.id])

  if (q.isLoading) return <Spinner />
  if (q.error) return <ErrorState error={q.error} retry={() => void q.refetch()} />
  const data = d!
  const left = Math.max(0, data.allowance.points_per_month - data.allowance.used_this_month)
  const s = detail.data?.data
  const err = fieldErrors(start.error)

  return (
    <div className="stack">
      <p className="muted">
        People nearby see different Google Maps results depending on where they stand. A map grid checks one search from a grid of points around your
        location and shows where you appear at each point. Each scan is one snapshot on a phone; results move during the day.
      </p>

      {!data.provider.configured ? (
        <p className="notice warn">Map positions are <strong>not measured yet</strong>. They appear once a maps results provider is connected.</p>
      ) : data.allowance.points_per_month === 0 ? (
        <p className="notice warn">Your plan does not include map grid checks. <Link to="/billing">Compare plans</Link>.</p>
      ) : data.locations.length === 0 ? (
        <Empty title="Add your map position first">
          Add the latitude and longitude of your shop in <Link to={`/businesses/${business.id}/profile`}>Business profile</Link>, then come back to scan.
        </Empty>
      ) : canEdit && (
        <form className="card stack" onSubmit={(e) => { e.preventDefault(); start.mutate() }}>
          <div className="grid" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '0.75rem' }}>
            <Field label="Search" hint="What a nearby customer types" error={err.keyword ?? err.keyword_id}>
              <input list="grid-keywords" value={keyword} onChange={(e) => setKeyword(e.target.value)} placeholder="makeup artist" maxLength={120} />
              <datalist id="grid-keywords">{data.keywords.map((k) => <option key={k.id} value={k.keyword} />)}</datalist>
            </Field>
            <Field label="Centre" error={err.location_id}>
              <select value={locationId} onChange={(e) => setLocationId(e.target.value)}>
                {data.locations.map((l) => <option key={l.id} value={l.id}>{l.label || l.city || `Location ${l.id}`}</option>)}
              </select>
            </Field>
            <Field label="Grid">
              <select value={size} onChange={(e) => setSize(Number(e.target.value))}>
                <option value={3}>3 × 3 (9 points)</option>
                <option value={5}>5 × 5 (25 points)</option>
              </select>
            </Field>
            <Field label="Distance between points">
              <select value={spacing} onChange={(e) => setSpacing(Number(e.target.value))}>
                {[0.5, 1, 2, 3].map((k) => <option key={k} value={k}>{k} km</option>)}
              </select>
            </Field>
          </div>
          <div className="row between" style={{ flexWrap: 'wrap' }}>
            <span className="small muted">{left} of {data.allowance.points_per_month} map points left this month.</span>
            <button className="btn primary" disabled={start.isPending || !!running || keyword.trim().length < 2 || size * size > left}>
              {running ? 'Scanning…' : 'Scan the map'}
            </button>
          </div>
          {err.grid && <p className="error-text">{err.grid[0]}</p>}
        </form>
      )}

      {running && <p className="notice">Scanning “{running.keyword}” from {running.size * running.size} points…</p>}

      {s && (
        <div className="card stack">
          <div className="row between" style={{ alignItems: 'flex-start', flexWrap: 'wrap' }}>
            <div>
              <h3 style={{ margin: 0 }}>“{s.keyword}”</h3>
              <div className="small muted">{s.size} × {s.size}, {s.spacing_km} km apart · {s.completed_at ? new Date(s.completed_at).toLocaleString() : humanize(s.status)}</div>
            </div>
            {s.status === 'partial' && <Badge tone="medium">Partly checked</Badge>}
          </div>
          {s.error && <p className="notice warn" style={{ margin: 0 }}>{s.error}</p>}
          <div className="grid" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(120px, 1fr))', gap: '0.75rem' }}>
            <div className="card stat"><div className="label">Points in the top 3</div><div className="value">{s.top_3_points}/{s.points_checked}</div></div>
            <div className="card stat"><div className="label">Points where you appear</div><div className="value">{s.points_found}/{s.points_checked}</div></div>
            <div className="card stat"><div className="label">Average position where found</div><div className="value">{s.average_position ?? '–'}</div></div>
          </div>
          <Grid scan={s} selected={point} onSelect={setPoint} />
          <p className="small muted" style={{ textAlign: 'center', margin: 0 }}>North is up; the dashed circle is your location. Green 1–3, amber 4–10, red 11+, grey not in the top {s.depth}. Tap a point.</p>
          {point !== null && s.points?.[point] && (
            <div className="small" style={{ background: 'var(--surface-2)', padding: '0.75rem', borderRadius: 8 }}>
              <strong>Top results at this point</strong>
              <ol style={{ margin: '0.25rem 0 0', paddingLeft: '1.2rem' }}>
                {s.points[point].top.map((t) => (
                  <li key={t.position} value={t.position}>
                    {t.you ? <strong>{t.title} (you)</strong> : t.title}
                    {t.rating !== null && <span className="muted"> · {t.rating}★{t.votes ? ` (${t.votes})` : ''}</span>}
                  </li>
                ))}
              </ol>
              {s.points[point].position && s.points[point].position! > 3 && <p className="muted" style={{ margin: '0.25rem 0 0' }}>You are number {s.points[point].position} here.</p>}
            </div>
          )}
          {(s.leaders?.length ?? 0) > 0 && (
            <div className="stack" style={{ gap: '0.25rem' }}>
              <strong className="small">Who wins the most points</strong>
              <ul className="small" style={{ margin: 0, paddingLeft: '1.1rem' }}>
                {s.leaders!.map((l) => (
                  <li key={l.title}>{l.title}{l.domain ? <span className="muted"> · {l.domain}</span> : null}: top 3 at {l.points_in_top_3} of {s.points_checked} points{l.rating !== null ? `, ${l.rating}★${l.votes ? ` from ${l.votes} reviews` : ''}` : ''}</li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}

      {data.data.length > 1 && (
        <div className="card table-wrap">
          <table>
            <thead><tr><th>Search</th><th>Grid</th><th>Top 3</th><th>Found</th><th>When</th><th /></tr></thead>
            <tbody>
              {data.data.map((x) => (
                <tr key={x.id}>
                  <td>{x.keyword}</td><td className="small">{x.size}×{x.size}, {x.spacing_km} km</td>
                  <td>{x.top_3_points}/{x.points_checked}</td><td>{x.points_found}/{x.points_checked}</td>
                  <td className="small">{new Date(x.created_at).toLocaleDateString()}</td>
                  <td>{['completed', 'partial'].includes(x.status) ? <button className="btn ghost sm" aria-pressed={currentId === x.id} onClick={() => setScanId(x.id)}>View</button> : <Badge>{humanize(x.status)}</Badge>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
