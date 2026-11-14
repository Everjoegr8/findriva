import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api, type BusinessDetail, type Overview } from '../../api'
import { useAuth } from '../../auth'
import { BarChart3, Globe, MapPin, Network, Search, Sparkles, Store } from 'lucide-react'
import { ErrorState, Meter, Spinner, StatusBadge, humanize } from '../../ui'
import { DiscoveryPath, type PathNode, type PathState } from '../../components/DiscoveryPath'

const SOCIAL = ['instagram', 'facebook', 'tiktok', 'whatsapp', 'x', 'linkedin', 'youtube']
const OFF = ['not_connected', 'not_set_up', 'not_started', 'revoked', 'expired', 'error', 'disconnected']
const isOn = (status?: string) => !!status && !OFF.includes(status)

/** One node per channel, lit only from the real state of that channel. */
function channels(o: Overview): PathNode[] {
  const p = o.profiles.by_platform
  const social = SOCIAL.reduce((n, k) => n + (p[k] ?? 0), 0)
  const gsc = o.search_engine_data.search_console?.status
  const bing = o.search_engine_data.bing_webmaster?.status
  const ranks = o.search_engine_data.rank_tracking?.status
  const auditDone = o.website?.last_audit?.status === 'completed'
  const st = (on: boolean, partial = false): PathState => (on ? 'live' : partial ? 'partial' : 'off')
  return [
    { key: 'google', label: 'Google Search', detail: isOn(gsc) ? 'Search Console connected' : 'Not connected yet', icon: Search, state: st(isOn(gsc)), to: 'results' },
    { key: 'maps', label: 'Maps profile', detail: p.google_business_profile ? 'Profile added' : 'Not added yet', icon: MapPin, state: st(!!p.google_business_profile), to: 'google-profile' },
    { key: 'website', label: 'Website', detail: o.website ? (auditDone ? 'Audited' : 'Audit pending') : 'No website (that is fine)', icon: Globe, state: st(!!o.website && auditDone, !!o.website), to: o.website ? 'audit' : 'public-page' },
    { key: 'social', label: 'Social & WhatsApp', detail: social ? `${social} profile${social === 1 ? '' : 's'}` : 'None added yet', icon: Network, state: st(social > 0), to: 'assets' },
    { key: 'bing', label: 'Bing', detail: isOn(bing) || p.bing_places ? 'Connected' : 'Not connected yet', icon: Store, state: st(isOn(bing) || !!p.bing_places), to: 'results' },
    { key: 'ranks', label: 'Rank tracking', detail: ranks === 'tracking' ? 'Tracking keywords' : 'Not started', icon: BarChart3, state: st(ranks === 'tracking'), to: 'rankings' },
  ]
}

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

  const nodes = channels(o)
  const live = nodes.filter((n) => n.state === 'live').length

  return (
    <div className="stack">
      <section className="hero-panel" aria-label="Where this business can be found">
        <div className="hero-copy">
          <span className="eyebrow">Discovery path</span>
          <h2>{live} of {nodes.length} channels are live</h2>
          <p>A moving signal means that channel is really connected. Grey dashed lines are the next places to show up.</p>
        </div>
        <DiscoveryPath hub={{ label: detail.data.name, detail: `Profile ${c.score}% complete`, icon: Sparkles }} nodes={nodes} />
      </section>
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
