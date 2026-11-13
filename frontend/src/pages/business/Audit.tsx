import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type Business, type CrawlRun, type Issue, type Paginated } from '../../api'
import { useAuth, useRole } from '../../auth'
import { Badge, Empty, ErrorState, SeverityBadge, Spinner, StatusBadge, humanize } from '../../ui'

function Evidence({ data }: { data: Record<string, unknown> | null }) {
  if (!data) return null
  const affected = data.affected as Array<Record<string, unknown>> | undefined
  const rest = Object.entries(data).filter(([k]) => k !== 'affected' && k !== 'affected_count' && k !== 'component')
  return (
    <div className="evidence">
      {affected && (
        <>
          <div className="small"><strong>{String(data.affected_count ?? affected.length)}</strong> affected:</div>
          <ul>
            {affected.slice(0, 10).map((a, i) => (
              <li key={i}>
                {String(a.url ?? a.platform ?? '')}
                {Object.entries(a).filter(([k]) => k !== 'url').map(([k, v]) => <span key={k} className="muted"> · {humanize(k)}: {typeof v === 'object' ? JSON.stringify(v) : String(v)}</span>)}
              </li>
            ))}
          </ul>
          {affected.length > 10 && <div className="muted">…and {affected.length - 10} more</div>}
        </>
      )}
      {rest.length > 0 && (
        <ul>
          {rest.map(([k, v]) => <li key={k}><span className="muted">{humanize(k)}:</span> {typeof v === 'object' ? JSON.stringify(v) : String(v)}</li>)}
        </ul>
      )}
    </div>
  )
}

export function IssueCard({ issue, onDismiss }: { issue: Issue; onDismiss?: () => void }) {
  const [open, setOpen] = useState(false)
  return (
    <div className={`card issue ${issue.severity}`}>
      <div className="row between">
        <div className="row"><SeverityBadge severity={issue.severity} /><strong>{issue.title}</strong></div>
        <div className="row">
          <Badge>{humanize(issue.category)}</Badge>
          {issue.action && <StatusBadge status={issue.action.status} />}
        </div>
      </div>
      <dl className="qa">
        <dt>Why it matters</dt><dd>{issue.why_it_matters}</dd>
        <dt>What we recommend</dt><dd>{issue.recommendation}</dd>
        {issue.access_needed && <><dt>Access needed</dt><dd>{issue.access_needed}</dd></>}
        <dt>How we'll verify</dt><dd>{issue.verification_method}</dd>
      </dl>
      <div className="row" style={{ marginTop: '0.5rem' }}>
        <button className="btn ghost sm" aria-expanded={open} onClick={() => setOpen(!open)}>{open ? 'Hide' : 'Show'} what we found</button>
        {issue.technical_name && <span className="small muted">Technical: {issue.technical_name}</span>}
        {onDismiss && issue.status === 'open' && <button className="btn ghost sm" onClick={onDismiss}>Not relevant</button>}
      </div>
      {open && <Evidence data={issue.what_we_found} />}
    </div>
  )
}

export default function AuditTab({ business }: { business: Business }) {
  const { workspaceId } = useAuth()
  const { canEdit } = useRole()
  const qc = useQueryClient()
  const [category, setCategory] = useState('')

  const runs = useQuery({
    queryKey: ['audits', workspaceId, business.id],
    queryFn: () => api<Paginated<CrawlRun>>(`/businesses/${business.id}/audits`),
    refetchInterval: (query) => {
      const latest = query.state.data?.data[0]
      return latest && (latest.status === 'queued' || latest.status === 'running') ? 3000 : false
    },
  })
  const latest = runs.data?.data[0]
  const running = latest && (latest.status === 'queued' || latest.status === 'running')

  const issues = useQuery({
    queryKey: ['issues', workspaceId, business.id, category, latest?.status],
    queryFn: () => api<Paginated<Issue>>(`/businesses/${business.id}/issues?per_page=100${category ? `&category=${category}` : ''}`),
  })

  const start = useMutation({
    mutationFn: () => api(`/businesses/${business.id}/audits`, { method: 'POST' }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['audits', workspaceId, business.id] }),
  })
  const dismiss = useMutation({
    mutationFn: (id: number) => api(`/businesses/${business.id}/issues/${id}`, { method: 'PATCH', json: { status: 'dismissed' } }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['issues', workspaceId, business.id] }),
  })

  const categories = Array.from(new Set((issues.data?.data ?? []).map((i) => i.category)))

  return (
    <div className="stack">
      <div className="card stack">
        <div className="row between">
          <div>
            <h2>Website check</h2>
            {business.has_website
              ? <p className="muted small" style={{ margin: 0 }}>We read your public pages the way a search engine does, within a safe page limit, and respect your robots.txt.</p>
              : <p className="muted small" style={{ margin: 0 }}>No website on file. Your profile and online profiles are still checked below.</p>}
          </div>
          {business.has_website && canEdit && (
            <button className="btn primary" disabled={!!running || start.isPending} onClick={() => start.mutate()}>{running ? 'Checking…' : 'Run a new audit'}</button>
          )}
        </div>
        {start.error && <ErrorState error={start.error} />}
        {latest && (
          <div className="grid small">
            <div><div className="muted">Last audit</div><StatusBadge status={latest.status} /> {latest.finished_at && new Date(latest.finished_at).toLocaleString()}</div>
            <div><div className="muted">Pages checked</div>{latest.pages_checked}{latest.site?.budget.stopped_reason === 'page_limit' && <span className="muted"> (plan limit reached)</span>}</div>
            {latest.site && <>
              <div><div className="muted">Secure (HTTPS)</div>{latest.site.uses_https ? 'Yes' : 'No'}</div>
              <div><div className="muted">robots.txt</div>{latest.site.robots.present ? (latest.site.robots.blocks_all ? 'Blocks search engines' : 'Present') : 'Missing'}</div>
              <div><div className="muted">Sitemap</div>{latest.site.sitemap.found ? `${latest.site.sitemap.valid ? 'Valid' : 'Invalid'}, ${latest.site.sitemap.url_count} URLs` : 'Missing'}</div>
              {latest.site.reconciliation && <div><div className="muted">Since last time</div>{latest.site.reconciliation.resolved} fixed · {latest.site.reconciliation.created} new</div>}
            </>}
            {latest.error && <div className="error-text">{latest.error}</div>}
          </div>
        )}
        {running && <Spinner label="Audit in progress" />}
      </div>

      <div className="row between">
        <h2 style={{ margin: 0 }}>What to improve</h2>
        <select style={{ width: 'auto' }} value={category} onChange={(e) => setCategory(e.target.value)} aria-label="Filter by category">
          <option value="">All areas</option>
          {categories.map((c) => <option key={c} value={c}>{humanize(c)}</option>)}
        </select>
      </div>
      {issues.isLoading && <Spinner />}
      {issues.error && <ErrorState error={issues.error} />}
      {issues.data?.data.length === 0 && <Empty title="No open issues">Nice. We'll keep checking and tell you if something changes.</Empty>}
      {issues.data?.data.map((i) => <IssueCard key={i.id} issue={i} onDismiss={canEdit ? () => dismiss.mutate(i.id) : undefined} />)}
    </div>
  )
}
