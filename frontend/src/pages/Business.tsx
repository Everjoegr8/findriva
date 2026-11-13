import { NavLink, Route, Routes, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api, type BusinessDetail } from '../api'
import { useAuth } from '../auth'
import { ErrorState, Spinner, Badge } from '../ui'
import OverviewTab from './business/Overview'
import ActionsTab from './business/Actions'
import AuditTab from './business/Audit'
import ProfileTab from './business/Profile'
import AssetsTab from './business/Assets'
import FixesTab from './business/Fixes'
import KeywordsTab from './business/Keywords'
import ContentTab from './business/Content'
import PublicPageTab from './business/PublicPage'

export function useBusiness(id: string | undefined) {
  const { workspaceId } = useAuth()
  return useQuery({ queryKey: ['business', workspaceId, id], queryFn: () => api<BusinessDetail>(`/businesses/${id}`), enabled: !!id })
}

export default function BusinessPage() {
  const { id } = useParams()
  const q = useBusiness(id)
  if (q.isLoading) return <Spinner />
  if (q.error) return <ErrorState error={q.error} retry={() => void q.refetch()} />
  if (!q.data) return null
  const b = q.data.data
  const base = `/businesses/${b.id}`

  return (
    <div>
      <div className="row between" style={{ marginBottom: '0.75rem' }}>
        <div>
          <h1 style={{ marginBottom: 0 }}>{b.name}</h1>
          <div className="row small muted">
            {b.primary_category && <span>{b.primary_category}</span>}
            {b.has_website ? <a href={b.website_url!} target="_blank" rel="noreferrer noopener">{b.website_url}</a> : <Badge>No website</Badge>}
          </div>
        </div>
      </div>
      <nav className="tabs">
        <NavLink end to={base}>Overview</NavLink>
        <NavLink to={`${base}/actions`}>Action Centre</NavLink>
        <NavLink to={`${base}/audit`}>Discoverability audit</NavLink>
        <NavLink to={`${base}/fixes`}>Ready-made fixes</NavLink>
        <NavLink to={`${base}/keywords`}>Keywords</NavLink>
        <NavLink to={`${base}/content`}>Content Studio</NavLink>
        <NavLink to={`${base}/public-page`}>Public page</NavLink>
        <NavLink to={`${base}/profile`}>Business profile</NavLink>
        <NavLink to={`${base}/assets`}>Profiles &amp; listings</NavLink>
      </nav>
      <Routes>
        <Route index element={<OverviewTab detail={q.data} />} />
        <Route path="actions" element={<ActionsTab businessId={b.id} />} />
        <Route path="audit" element={<AuditTab business={b} />} />
        <Route path="fixes" element={<FixesTab business={b} />} />
        <Route path="keywords" element={<KeywordsTab business={b} />} />
        <Route path="content" element={<ContentTab business={b} />} />
        <Route path="public-page" element={<PublicPageTab business={b} />} />
        <Route path="profile" element={<ProfileTab detail={q.data} />} />
        <Route path="assets" element={<AssetsTab business={b} />} />
      </Routes>
    </div>
  )
}
