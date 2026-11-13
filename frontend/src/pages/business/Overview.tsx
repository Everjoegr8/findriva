import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api, type BusinessDetail, type Overview } from '../../api'
import { useAuth } from '../../auth'
import { ErrorState, Meter, Spinner, StatusBadge, humanize } from '../../ui'

const COMPONENT_LABEL: Record<string, string> = {
  name: 'Business name', category: 'Category', description: 'Description', location: 'Location or service area', phone: 'Phone or WhatsApp',
  email: 'Email', hours: 'Opening hours', offerings: 'Services or products', online_presence: 'At least one online profile', website: 'Website (optional)',
}

/** Each measure stays separate: profile completeness, website health, work done and search-engine data are different things. */
export default function OverviewTab({ detail }: { detail: BusinessDetail }) {
  const { workspaceId } = useAuth()
  const id = detail.data.id
  const q = useQuery({ queryKey: ['overview', workspaceId, id], queryFn: () => api<{ data: Overview }>(`/businesses/${id}/overview`), refetchInterval: 15_000 })
  if (q.isLoading) return <Spinner />
  if (q.error) return <ErrorState error={q.error} />
  const o = q.data!.data
  const c = o.identity_completeness

  return (
    <div className="stack">
      <div className="grid">
        <div className="card stat">
          <div className="label">Business profile completeness</div>
          <div className="value">{c.score}%</div>
          <Meter value={c.score} />
          <p className="small muted" style={{ marginTop: '0.5rem' }}>How complete and consistent your own business details are.</p>
        </div>
        <div className="card stat">
          <div className="label">Website health</div>
          {o.website ? (
            <>
              <div className="value">{Object.values(o.website.open_issues_by_severity).reduce((a, b) => a + b, 0)}</div>
              <p className="small muted">open website issues {o.website.last_audit?.status && <StatusBadge status={o.website.last_audit.status} />}</p>
            </>
          ) : (
            <><div className="value">–</div><p className="small muted">No website yet. That's okay; we're working with your profiles.</p></>
          )}
        </div>
        <div className="card stat">
          <div className="label">Improvements</div>
          <div className="value">{o.actions.verified}</div>
          <p className="small muted">verified by a re-check · {o.actions.done_unverified} done and waiting for a check · {o.actions.open} to do</p>
        </div>
        <div className="card stat">
          <div className="label">Online profiles</div>
          <div className="value">{o.profiles.registered}</div>
          <p className="small muted">{o.profiles.reachable} publicly reachable</p>
        </div>
      </div>

      {o.actions.awaiting_approval > 0 && (
        <div className="notice">{o.actions.awaiting_approval} change(s) are waiting for your approval. <Link to="actions">Review them</Link></div>
      )}

      {detail.identity.conflicts.length > 0 && (
        <div className="card stack">
          <h2>Details that disagree</h2>
          <ul>{detail.identity.conflicts.map((cf, i) => <li key={i}>{cf.message} <span className="muted small">({cf.values.join(' vs ')})</span></li>)}</ul>
          <Link to="profile" className="btn sm" style={{ alignSelf: 'flex-start' }}>Review business profile</Link>
        </div>
      )}

      <div className="card">
        <h2>What's in your profile</h2>
        <div className="grid small">
          {Object.entries(c.components).map(([k, ok]) => (
            <div key={k} className="row"><span aria-hidden>{ok ? '✅' : '⬜'}</span> {COMPONENT_LABEL[k] ?? humanize(k)}</div>
          ))}
        </div>
      </div>

      <div className="card">
        <h2>Search engine data</h2>
        <p className="small muted">We only show search numbers that come from a real source. Nothing here is estimated.</p>
        <table>
          <tbody>
            {Object.entries(o.search_engine_data).map(([k, v]) => (
              <tr key={k}><th>{humanize(k)}</th><td>{humanize(v.status)}</td><td className="small muted">{v.last_28_days ? `${v.last_28_days.clicks.toLocaleString()} clicks and ${v.last_28_days.impressions.toLocaleString()} impressions in the 28 days to ${v.data_through}` : v.note}</td></tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
