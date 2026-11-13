import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api, type Business, type Paginated } from '../api'
import { useAuth } from '../auth'
import { Empty, ErrorState, Spinner, Badge, humanize } from '../ui'

interface Coverage { plan: string; label: string; limits: Record<string, number>; usage: Record<string, number> }

export default function BusinessesPage() {
  const { workspaceId } = useAuth()
  const list = useQuery({ queryKey: ['businesses', workspaceId], queryFn: () => api<Paginated<Business>>('/businesses') })
  const coverage = useQuery({ queryKey: ['coverage', workspaceId], queryFn: () => api<{ data: Coverage }>('/coverage') })

  return (
    <div className="stack">
      <div className="row between">
        <div>
          <h1>Businesses</h1>
          <p className="muted">Every business you manage, how complete its profile is and what needs doing next.</p>
        </div>
        <Link className="btn primary" to="/businesses/new">Add a business</Link>
      </div>

      {coverage.data && (
        <div className="card row between small">
          <span><strong>{coverage.data.data.label}</strong> plan</span>
          <span className="muted">{coverage.data.data.usage.businesses} of {coverage.data.data.limits.businesses} businesses</span>
          <span className="muted">{coverage.data.data.usage.crawls_this_month} of {coverage.data.data.limits.crawls_per_month} website audits this month</span>
          <span className="muted">Up to {coverage.data.data.limits.pages_per_crawl} pages per audit</span>
        </div>
      )}

      {list.isLoading && <Spinner />}
      {list.error && <ErrorState error={list.error} retry={() => void list.refetch()} />}
      {list.data && list.data.data.length === 0 && (
        <Empty title="No businesses yet">
          <p>Start with the name. A website is optional: we can work with Instagram, WhatsApp, TikTok or nothing at all.</p>
          <Link className="btn primary" to="/businesses/new">Add your first business</Link>
        </Empty>
      )}
      {list.data && list.data.data.length > 0 && (
        <div className="grid">
          {list.data.data.map((b) => (
            <Link key={b.id} to={`/businesses/${b.id}`} className="card stack" style={{ textDecoration: 'none', color: 'inherit' }}>
              <div className="row between"><h2 style={{ margin: 0 }}>{b.name}</h2><Badge tone={b.status === 'active' ? 'ok' : ''}>{humanize(b.status)}</Badge></div>
              <div className="row small muted">
                {b.has_website ? <Badge tone="brand">Website</Badge> : <Badge>No website</Badge>}
                <span>{b.locations_count ?? 0} locations</span>
                <span>{b.assets_count ?? 0} profiles</span>
              </div>
              <div className="small">{b.open_issues_count ?? 0} things to improve</div>
            </Link>
          ))}
        </div>
      )}
    </div>
  )
}
