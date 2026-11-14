import { Route, Routes, useLocation, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api, type BusinessDetail } from '../api'
import { useAuth } from '../auth'
import { ErrorState, Spinner, Badge, PageTitle } from '../ui'
import { BUSINESS_NAV } from '../layouts/nav'
import OverviewTab from './business/Overview'
import ActionsTab from './business/Actions'
import AuditTab from './business/Audit'
import ProfileTab from './business/Profile'
import AssetsTab from './business/Assets'
import FixesTab from './business/Fixes'
import KeywordsTab from './business/Keywords'
import ContentTab from './business/Content'
import PublicPageTab from './business/PublicPage'
import ResultsTab from './business/Results'
import RankingsTab from './business/Rankings'
import MapGridTab from './business/MapGrid'
import GoogleProfileTab from './business/GoogleProfile'
import AiAnswersTab from './business/AiAnswers'
import WordPressTab from './business/WordPress'

export function useBusiness(id: string | undefined) {
  const { workspaceId } = useAuth()
  return useQuery({ queryKey: ['business', workspaceId, id], queryFn: () => api<BusinessDetail>(`/businesses/${id}`), enabled: !!id })
}

export default function BusinessPage() {
  const { id } = useParams()
  const { pathname } = useLocation()
  const q = useBusiness(id)
  if (q.isLoading) return <Spinner />
  if (q.error) return <ErrorState error={q.error} retry={() => void q.refetch()} />
  if (!q.data) return null
  const b = q.data.data
  const sub = pathname.replace(/^\/businesses\/[^/]+\/?/, '').split('/')[0]
  const section = BUSINESS_NAV.flatMap((g) => g.items).find((n) => n.path === sub)

  return (
    <div>
      <PageTitle
        eyebrow={section?.label ?? 'Overview'}
        title={b.name}
        sub={
          <span className="row small" style={{ gap: '0.6rem' }}>
            {b.primary_category && <span>{b.primary_category}</span>}
            {b.has_website ? <a href={b.website_url!} target="_blank" rel="noreferrer noopener">{b.website_url}</a> : <Badge>No website</Badge>}
          </span>
        }
      />
      <Routes>
        <Route index element={<OverviewTab detail={q.data} />} />
        <Route path="results" element={<ResultsTab business={b} />} />
        <Route path="actions" element={<ActionsTab businessId={b.id} />} />
        <Route path="audit" element={<AuditTab business={b} />} />
        <Route path="fixes" element={<FixesTab business={b} />} />
        <Route path="keywords" element={<KeywordsTab business={b} />} />
        <Route path="rankings" element={<RankingsTab business={b} />} />
        <Route path="map-grid" element={<MapGridTab business={b} />} />
        <Route path="google-profile" element={<GoogleProfileTab business={b} />} />
        <Route path="ai-answers" element={<AiAnswersTab business={b} />} />
        <Route path="content" element={<ContentTab business={b} />} />
        <Route path="public-page" element={<PublicPageTab business={b} />} />
        <Route path="website" element={<WordPressTab business={b} />} />
        <Route path="profile" element={<ProfileTab detail={q.data} />} />
        <Route path="assets" element={<AssetsTab business={b} />} />
      </Routes>
    </div>
  )
}
