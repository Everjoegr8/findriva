import { Fragment, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type Business } from '../../api'
import { useAuth, useRole } from '../../auth'
import { Badge, Empty, ErrorState, Field, Spinner, StatusBadge, fieldErrors, humanize } from '../../ui'

interface TopResult { position: number; domain: string; url: string; title: string }
interface Row {
  keyword_id: number; keyword: string; intent: string; search_volume: number | null; within_allowance: boolean
  checked: boolean; position: number | null; url: string | null; depth: number | null; checked_at: string | null
  previous_position: number | null; change: number | null; serp_features: string[]; top_results: TopResult[]
  history: { date: string; position: number | null }[]
}
interface RunSummary { status: string; message: string; checked: number; finished_at: string }
interface RankingsResponse {
  data: Row[]
  summary: { tracked: number; checked: number; top_3: number; top_10: number; not_found: number; last_checked_at: string | null }
  method: { provider: string; configured: boolean; market: string; device: string; depth: number; cadence_days: number }
  allowance: number; cap_usd_per_day: number; last_run: RunSummary | null
}
interface Competitor {
  id: number; domain: string; name: string | null; kind: string; source: string; status: string
  shared_keywords: number; best_position: number | null; average_position: number | null
  keywords: { keyword: string; their_position: number; their_url: string; your_position: number | null }[]
}

const FEATURE_LABEL: Record<string, string> = {
  local_pack: 'Map results', people_also_ask: 'People also ask', images: 'Images', video: 'Videos', featured_snippet: 'Featured answer',
  ai_overview: 'AI overview', knowledge_graph: 'Knowledge panel', top_stories: 'News', shopping: 'Shopping', paid: 'Ads',
}
const OK_STATUSES = ['checked', 'up_to_date']

function Position({ value, depth }: { value: number | null; depth: number | null }) {
  if (value === null) return <span className="small muted">Not in top {depth ?? 30}</span>
  return <strong>{value}</strong>
}

function Change({ value }: { value: number | null }) {
  if (value === null || value === 0) return <span className="small muted">–</span>
  return value > 0 ? <Badge tone="ok">▲ {value}</Badge> : <Badge tone="high">▼ {-value}</Badge>
}

/** Tiny inline trend: lower position = higher on the chart. Gaps where the business wasn't found. */
function Trend({ history, depth }: { history: Row['history']; depth: number }) {
  if (history.length < 2) return null
  const w = 96, h = 26, max = depth
  const x = (i: number) => (i / (history.length - 1)) * (w - 4) + 2
  const y = (p: number) => 2 + ((Math.min(p, max) - 1) / (max - 1)) * (h - 4)
  const pts = history.map((p, i) => (p.position === null ? null : `${x(i)},${y(p.position)}`))
  return (
    <svg width={w} height={h} role="img" aria-label={`Positions: ${history.map((p) => p.position ?? 'not found').join(', ')}`}>
      <polyline fill="none" stroke="var(--brand)" strokeWidth="2" points={pts.filter(Boolean).join(' ')} />
      {history.map((p, i) => p.position !== null && <circle key={i} cx={x(i)} cy={y(p.position)} r="2" fill="var(--brand)" />)}
    </svg>
  )
}

export default function RankingsTab({ business }: { business: Business }) {
  const { workspaceId } = useAuth()
  const { canEdit } = useRole()
  const qc = useQueryClient()
  const [waitingSince, setWaitingSince] = useState<number | null>(null)
  const [open, setOpen] = useState<number | null>(null)
  const key = ['rankings', workspaceId, business.id]
  const ckey = ['competitors', workspaceId, business.id]

  const q = useQuery({
    queryKey: key,
    queryFn: () => api<RankingsResponse>(`/businesses/${business.id}/rankings`),
    refetchInterval: waitingSince === null ? false : 3000,
  })
  const finishedAt = q.data?.last_run?.finished_at
  useEffect(() => {
    if (waitingSince === null) return
    const done = finishedAt && Date.parse(finishedAt) >= waitingSince - 2000
    const timedOut = Date.now() - waitingSince > 120_000
    if (done || timedOut) {
      setWaitingSince(null)
      void qc.invalidateQueries({ queryKey: ['competitors', workspaceId, business.id] })
    }
  }, [finishedAt, waitingSince, qc, workspaceId, business.id])
  const competitors = useQuery({ queryKey: ckey, queryFn: () => api<{ data: Competitor[]; checked_keywords: number }>(`/businesses/${business.id}/competitors`) })
  const check = useMutation({
    mutationFn: () => api(`/businesses/${business.id}/rankings/check`, { method: 'POST' }),
    onSuccess: () => { setWaitingSince(Date.now()); void qc.invalidateQueries({ queryKey: key }) },
  })

  if (q.isLoading) return <Spinner />
  if (q.error) return <ErrorState error={q.error} retry={() => void q.refetch()} />
  const d = q.data!
  const s = d.summary
  const checking = waitingSince !== null || check.isPending

  return (
    <div className="stack">
      <p className="muted">
        Where you appear in Google for the keywords you track. Each check is one snapshot of live results ({humanize(d.method.device)}, {d.method.market}),
        so positions can differ for people in other places or on phones. Search engines decide rankings; this shows them, it cannot buy them.
      </p>

      <div className="card row between">
        <div className="small">
          {d.method.configured
            ? d.allowance > 0
              ? <>Positions come from <strong>{humanize(d.method.provider)}</strong> and refresh every {d.method.cadence_days} days. Your plan covers {d.allowance} tracked keywords.</>
              : <>Your plan does not include position checks. <Link to="/billing">Compare plans</Link>. Search Console positions (if connected) are on Results.</>
            : <>Positions are <strong>not measured yet</strong>. They appear once a search results provider is connected. Search Console positions (if connected) are on Results.</>}
        </div>
        {canEdit && s.tracked > 0 && d.method.configured && d.allowance > 0 && (
          <button className="btn primary" disabled={checking} onClick={() => check.mutate()}>{checking ? 'Checking…' : 'Check positions now'}</button>
        )}
      </div>
      {d.last_run && !checking && <p className={`notice ${OK_STATUSES.includes(d.last_run.status) ? '' : 'warn'}`}>{d.last_run.message}</p>}

      {s.tracked === 0 ? (
        <Empty title="No tracked keywords yet">
          Choose the searches that matter on the <Link to={`/businesses/${business.id}/keywords`}>Keywords</Link> tab and press Track. Positions for them are checked weekly.
        </Empty>
      ) : (
        <>
          <div className="grid" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: '0.75rem' }}>
            <div className="card stat"><div className="label">Tracked keywords</div><div className="value">{s.tracked}</div></div>
            <div className="card stat"><div className="label">In the top 3</div><div className="value">{s.checked ? s.top_3 : '–'}</div></div>
            <div className="card stat"><div className="label">On page one</div><div className="value">{s.checked ? s.top_10 : '–'}</div></div>
            <div className="card stat"><div className="label">Not found yet</div><div className="value">{s.checked ? s.not_found : '–'}</div></div>
          </div>

          <div className="card table-wrap">
            <table>
              <thead><tr><th>Keyword</th><th>Position</th><th>Change</th><th>Trend</th><th>Your result</th><th>Checked</th></tr></thead>
              <tbody>
                {d.data.map((r) => (
                  <Fragment key={r.keyword_id}>
                    <tr>
                      <td>
                        {r.keyword}
                        {r.search_volume !== null && <div className="small muted">{r.search_volume.toLocaleString()} searches a month</div>}
                        {d.allowance > 0 && !r.within_allowance && <div className="small muted">Beyond your plan's allowance</div>}
                      </td>
                      <td>{r.checked ? <Position value={r.position} depth={r.depth} /> : <span className="small muted">Not measured</span>}</td>
                      <td><Change value={r.change} /></td>
                      <td><Trend history={r.history} depth={d.method.depth} /></td>
                      <td className="small" style={{ maxWidth: 260, overflowWrap: 'anywhere' }}>
                        {r.url ? <a href={r.url} target="_blank" rel="noreferrer noopener">{r.url.replace(/^https?:\/\/(www\.)?/, '')}</a> : '–'}
                      </td>
                      <td className="small">
                        {r.checked_at ? new Date(r.checked_at).toLocaleDateString() : '–'}
                        {r.top_results.length > 0 && <div><button className="btn ghost sm" aria-expanded={open === r.keyword_id} onClick={() => setOpen(open === r.keyword_id ? null : r.keyword_id)}>{open === r.keyword_id ? 'Hide results' : 'See page one'}</button></div>}
                      </td>
                    </tr>
                    {open === r.keyword_id && (
                      <tr>
                        <td colSpan={6} style={{ background: 'var(--surface-2)' }}>
                          {r.serp_features.filter((f) => FEATURE_LABEL[f]).length > 0 && (
                            <p className="small">Also on the page: {r.serp_features.filter((f) => FEATURE_LABEL[f]).map((f) => FEATURE_LABEL[f]).join(', ')}</p>
                          )}
                          <ol className="small" style={{ margin: 0, paddingLeft: '1.25rem' }}>
                            {r.top_results.map((t) => (
                              <li key={t.position + t.url} value={t.position} style={{ overflowWrap: 'anywhere' }}>
                                {t.url === r.url ? <strong>{t.title || t.domain} (you)</strong> : (t.title || t.domain)} <span className="muted">· {t.domain}</span>
                              </li>
                            ))}
                          </ol>
                        </td>
                      </tr>
                    )}
                  </Fragment>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}

      <CompetitorsSection business={business} data={competitors.data} loading={competitors.isLoading} canEdit={canEdit} />
    </div>
  )
}

function CompetitorsSection({ business, data, loading, canEdit }: { business: Business; data?: { data: Competitor[]; checked_keywords: number }; loading: boolean; canEdit: boolean }) {
  const { workspaceId } = useAuth()
  const qc = useQueryClient()
  const [domain, setDomain] = useState('')
  const [kind, setKind] = useState<'business' | 'platform'>('business')
  const ckey = ['competitors', workspaceId, business.id]
  const add = useMutation({
    mutationFn: () => api(`/businesses/${business.id}/competitors`, { method: 'POST', json: { domain } }),
    onSuccess: () => { setDomain(''); void qc.invalidateQueries({ queryKey: ckey }) },
  })
  const update = useMutation({
    mutationFn: ({ id, ...json }: { id: number; status?: string; kind?: string }) => api(`/businesses/${business.id}/competitors/${id}`, { method: 'PATCH', json }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ckey }),
  })
  const errors = fieldErrors(add.error)
  const list = (data?.data ?? []).filter((c) => c.kind === kind)

  return (
    <section className="stack">
      <h2 style={{ marginBottom: 0 }}>Who else shows up</h2>
      <p className="muted small">
        Sites that appear on page one for your tracked keywords, from the latest check of each. Directories and social networks are listed separately:
        they are places to be listed, not rivals.
      </p>
      <div className="chips" role="group" aria-label="Competitor type">
        <button aria-pressed={kind === 'business'} onClick={() => setKind('business')}>Businesses</button>
        <button aria-pressed={kind === 'platform'} onClick={() => setKind('platform')}>Directories &amp; platforms</button>
      </div>
      {loading ? <Spinner /> : list.length === 0 ? (
        <Empty title={kind === 'business' ? 'No competitors found yet' : 'No directories found yet'}>
          {data?.checked_keywords ? 'None appeared on page one for your tracked keywords.' : 'They appear after the first position check. You can also add one you know about.'}
        </Empty>
      ) : (
        <div className="grid" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(min(100%, 300px), 1fr))', gap: '0.75rem' }}>
          {list.map((c) => (
            <div key={c.id} className="card stack" style={{ gap: '0.5rem' }}>
              <div className="row between" style={{ alignItems: 'flex-start' }}>
                <div style={{ minWidth: 0, overflowWrap: 'anywhere' }}>
                  <a href={`https://${c.domain}`} target="_blank" rel="noreferrer noopener"><strong>{c.domain}</strong></a>
                  {c.name && <div className="small muted">{c.name}</div>}
                  {c.source === 'manual' && <div className="small muted">Added by you</div>}
                </div>
                <StatusBadge status={c.status} />
              </div>
              <div className="small">
                On page one for <strong>{c.shared_keywords}</strong> of your keywords{c.best_position !== null && <>, best position <strong>{c.best_position}</strong></>}.
              </div>
              {c.keywords.length > 0 ? (
                <ul className="small" style={{ margin: 0, paddingLeft: '1.1rem' }}>
                  {c.keywords.slice(0, 4).map((k) => (
                    <li key={k.keyword}>{k.keyword}: them {k.their_position}, you {k.your_position ?? 'not found'}</li>
                  ))}
                  {c.keywords.length > 4 && <li className="muted">and {c.keywords.length - 4} more</li>}
                </ul>
              ) : <div className="small muted">Not on page one for your keywords right now.</div>}
              {canEdit && (
                <div className="row" style={{ flexWrap: 'wrap' }}>
                  {c.status !== 'tracked' && <button className="btn ghost sm" onClick={() => update.mutate({ id: c.id, status: 'tracked' })}>Watch</button>}
                  {c.status === 'tracked' && <button className="btn ghost sm" onClick={() => update.mutate({ id: c.id, status: 'suggested' })}>Stop watching</button>}
                  <button className="btn ghost sm" onClick={() => update.mutate({ id: c.id, kind: c.kind === 'business' ? 'platform' : 'business' })}>
                    {c.kind === 'business' ? 'It’s a directory' : 'It’s a business'}
                  </button>
                  <button className="btn ghost sm danger" onClick={() => update.mutate({ id: c.id, status: 'dismissed' })}>Hide</button>
                </div>
              )}
            </div>
          ))}
        </div>
      )}
      {canEdit && (
        <form className="card row" style={{ alignItems: 'flex-end', flexWrap: 'wrap' }} onSubmit={(e) => { e.preventDefault(); if (domain.trim()) add.mutate() }}>
          <div style={{ flex: '1 1 240px' }}>
            <Field label="Add a competitor you know" hint="Their website, e.g. rival.com.ng" error={errors.domain}>
              <input value={domain} onChange={(e) => setDomain(e.target.value)} placeholder="rival.com.ng" />
            </Field>
          </div>
          <button className="btn" disabled={add.isPending || !domain.trim()}>Add</button>
        </form>
      )}
    </section>
  )
}
