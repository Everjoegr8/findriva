import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api, type Business, type Paginated } from '../api'
import { useAuth } from '../auth'
import { ArrowRight, Globe, MapPin, Network, Plus } from 'lucide-react'
import { Empty, ErrorState, Spinner, Badge, humanize } from '../ui'
import { photos, src, srcSet } from './landing/photos'

function greeting() {
  const h = new Date().getHours()
  return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening'
}

interface Coverage { plan: string; label: string; limits: Record<string, number>; usage: Record<string, number> }

export default function BusinessesPage() {
  const { user, workspaceId } = useAuth()
  const list = useQuery({ queryKey: ['businesses', workspaceId], queryFn: () => api<Paginated<Business>>('/businesses') })
  const coverage = useQuery({ queryKey: ['coverage', workspaceId], queryFn: () => api<{ data: Coverage }>('/coverage') })

  return (
    <div className="stack">
      <section className="welcome">
        <img className="welcome-photo" src={src(photos.market, 1200, 420)} srcSet={srcSet(photos.market, 1200, 420)} alt="" loading="eager" />
        <div className="welcome-body">
          <span className="eyebrow">{greeting()}, {user?.name.split(' ')[0]}</span>
          <h1>Your businesses</h1>
          <p>How complete each profile is and what needs doing next, all in one place.</p>
          <Link className="btn primary" to="/businesses/new"><Plus size={16} aria-hidden /> Add a business</Link>
        </div>
        <a className="photo-credit" href={photos.market.page} target="_blank" rel="noreferrer noopener">Photo: {photos.market.by} / Unsplash</a>
      </section>

      {coverage.data && (() => {
        const c = coverage.data.data
        const pct = (u: number, l: number) => (l ? Math.min(100, Math.round((u / l) * 100)) : 0)
        return (
          <div className="usage-strip">
            <div><span className="muted small">Plan</span><strong>{c.label}</strong><Link to="/billing" className="small">Manage</Link></div>
            <div><span className="muted small">Businesses</span><strong>{c.usage.businesses} of {c.limits.businesses}</strong><span className="bar"><span style={{ width: `${pct(c.usage.businesses, c.limits.businesses)}%` }} /></span></div>
            <div><span className="muted small">Website audits this month</span><strong>{c.usage.crawls_this_month} of {c.limits.crawls_per_month}</strong><span className="bar"><span style={{ width: `${pct(c.usage.crawls_this_month, c.limits.crawls_per_month)}%` }} /></span></div>
            <div><span className="muted small">Pages per audit</span><strong>Up to {c.limits.pages_per_crawl}</strong></div>
          </div>
        )
      })()}

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
            <Link key={b.id} to={`/businesses/${b.id}`} className="card biz-card">
              <div className="biz-card-head">
                <span className="biz-avatar" aria-hidden>{b.name.slice(0, 1).toUpperCase()}</span>
                <div className="biz-card-title"><h2>{b.name}</h2><span className="small muted">{b.primary_category ?? 'No category yet'}</span></div>
                <Badge tone={b.status === 'active' ? 'ok' : ''}>{humanize(b.status)}</Badge>
              </div>
              <div className="biz-card-facts small muted">
                <span><Globe size={14} aria-hidden /> {b.has_website ? 'Website' : 'No website'}</span>
                <span><MapPin size={14} aria-hidden /> {b.locations_count ?? 0} location{b.locations_count === 1 ? '' : 's'}</span>
                <span><Network size={14} aria-hidden /> {b.assets_count ?? 0} profile{b.assets_count === 1 ? '' : 's'}</span>
              </div>
              <div className="biz-card-foot"><span>{b.open_issues_count ?? 0} things to improve</span><ArrowRight size={16} aria-hidden /></div>
            </Link>
          ))}
        </div>
      )}
    </div>
  )
}
