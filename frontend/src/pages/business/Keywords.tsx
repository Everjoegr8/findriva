import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type Business } from '../../api'
import { useAuth, useRole } from '../../auth'
import { Badge, Empty, ErrorState, Spinner, StatusBadge, humanize } from '../../ui'

interface Keyword {
  id: number; keyword: string; intent: string; status: string; cluster_id: number | null; origin: string
  measured: boolean; search_volume: number | null; competition: number | null; cpc: number | null; cpc_currency: string | null
  metrics_provider: string | null; metrics_retrieved_at: string | null
}
interface KeywordsResponse {
  data: Keyword[]; clusters: { id: number; name: string; theme: string; keywords_count: number }[]
  provider: { name: string; configured: boolean }; budget: { cap_usd_per_day: number }
}
interface ResearchSummary { status: string; message: string; seeds: number; measured: number; ideas_added: number }

const INTENTS = ['transactional', 'local', 'commercial', 'informational', 'navigational']
const INTENT_HELP: Record<string, string> = {
  transactional: 'Ready to buy or book', local: 'Looking nearby', commercial: 'Comparing options',
  informational: 'Researching', navigational: 'Looking for you by name',
}

export default function KeywordsTab({ business }: { business: Business }) {
  const { workspaceId } = useAuth()
  const { canEdit } = useRole()
  const qc = useQueryClient()
  const [intent, setIntent] = useState<string | null>(null)
  const [cluster, setCluster] = useState<number | null>(null)
  const [summary, setSummary] = useState<ResearchSummary | null>(null)
  const key = ['keywords', workspaceId, business.id]
  const q = useQuery({ queryKey: key, queryFn: () => api<KeywordsResponse>(`/businesses/${business.id}/keywords`) })
  const research = useMutation({
    mutationFn: () => api<{ data: ResearchSummary }>(`/businesses/${business.id}/keywords/research`, { method: 'POST' }),
    onSuccess: (r) => { setSummary(r.data); void qc.invalidateQueries({ queryKey: key }) },
  })
  const setStatus = useMutation({
    mutationFn: ({ id, status }: { id: number; status: string }) => api(`/businesses/${business.id}/keywords/${id}`, { method: 'PATCH', json: { status } }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: key }),
  })

  if (q.isLoading) return <Spinner />
  if (q.error) return <ErrorState error={q.error} retry={() => void q.refetch()} />
  const d = q.data!
  const rows = d.data.filter((k) => (!intent || k.intent === intent) && (!cluster || k.cluster_id === cluster))
  const measured = d.data.some((k) => k.measured)

  return (
    <div className="stack">
      <p className="muted">Phrases your customers are likely to search, built from your services, products, category and the places you serve. They are ideas to test, not promises of traffic.</p>
      <div className="card row between">
        <div className="small">
          {d.provider.configured
            ? <>Search volumes come from <strong>{humanize(d.provider.name)}</strong> (Google Ads data for {business.country}). Daily budget for this workspace: ${d.budget.cap_usd_per_day.toFixed(2)}.</>
            : <>Search volumes are <strong>not measured yet</strong>. They appear once a keyword data provider is connected.</>}
        </div>
        {canEdit && <button className="btn primary" disabled={research.isPending} onClick={() => research.mutate()}>{research.isPending ? 'Researching…' : d.data.length ? 'Refresh ideas' : 'Find keyword ideas'}</button>}
      </div>
      {summary && <p className={`notice ${['measured', 'cached'].includes(summary.status) ? '' : 'warn'}`}>{summary.message}</p>}

      {d.data.length === 0 ? (
        <Empty title="No keyword ideas yet">Add your services or products in Business profile, then find keyword ideas.</Empty>
      ) : (
        <>
          <div className="chips" role="group" aria-label="Filter by intent">
            <button aria-pressed={intent === null} onClick={() => setIntent(null)}>All intents</button>
            {INTENTS.map((i) => <button key={i} aria-pressed={intent === i} title={INTENT_HELP[i]} onClick={() => setIntent(intent === i ? null : i)}>{humanize(i)}</button>)}
          </div>
          {d.clusters.length > 1 && (
            <div className="chips" role="group" aria-label="Filter by topic">
              <button aria-pressed={cluster === null} onClick={() => setCluster(null)}>All topics</button>
              {d.clusters.map((c) => <button key={c.id} aria-pressed={cluster === c.id} onClick={() => setCluster(cluster === c.id ? null : c.id)}>{c.name} ({c.keywords_count})</button>)}
            </div>
          )}
          <div className="card table-wrap">
            <table>
              <thead><tr><th>Keyword</th><th>Intent</th><th>Monthly searches</th>{measured && <th>Competition</th>}<th>Status</th><th /></tr></thead>
              <tbody>
                {rows.map((k) => (
                  <tr key={k.id}>
                    <td>{k.keyword}{k.origin !== 'seed' && <div className="small muted">Suggested by {humanize(k.origin)}</div>}</td>
                    <td><span title={INTENT_HELP[k.intent]}><Badge tone={k.intent === 'transactional' || k.intent === 'local' ? 'brand' : ''}>{humanize(k.intent)}</Badge></span></td>
                    <td>{k.measured && k.search_volume !== null ? k.search_volume.toLocaleString() : <span className="small muted">Not measured</span>}</td>
                    {measured && <td className="small">{k.competition !== null ? (k.competition < 0.34 ? 'Low' : k.competition < 0.67 ? 'Medium' : 'High') : '–'}</td>}
                    <td><StatusBadge status={k.status} /></td>
                    <td>{canEdit && <div className="row">
                      {k.status !== 'tracked' && <button className="btn ghost sm" onClick={() => setStatus.mutate({ id: k.id, status: 'tracked' })}>Track</button>}
                      {k.status === 'tracked' && <button className="btn ghost sm" onClick={() => setStatus.mutate({ id: k.id, status: 'suggested' })}>Untrack</button>}
                      <button className="btn ghost sm danger" onClick={() => setStatus.mutate({ id: k.id, status: 'dismissed' })}>Hide</button>
                    </div>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {measured && <p className="small muted">Measured volumes are Google Ads estimates for the whole country and are refreshed at most once every 30 days.</p>}
        </>
      )}
    </div>
  )
}
