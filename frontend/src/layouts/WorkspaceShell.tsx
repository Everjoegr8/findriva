import { lazy, Suspense, useMemo, useState } from 'react'
import { Link, Navigate, Outlet, useLocation, useMatch, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Building2, LayoutGrid, Plus, ShieldHalf } from 'lucide-react'
import { api, type Business, type Paginated } from '../api'
import { useAuth } from '../auth'
import { VerifyBanner } from '../VerifyBanner'
import { CommandPalette, useCommandShortcut, type Command } from '../components/CommandPalette'
import { Brand, Frame, SideGroup, SideLink, Switcher, UserCard } from './Frame'
import { BUSINESS_NAV, WORKSPACE_NAV } from './nav'

const LandingPage = lazy(() => import('../pages/Landing'))

export function useBusinessList() {
  const { user, workspaceId } = useAuth()
  return useQuery({ queryKey: ['businesses', workspaceId], queryFn: () => api<Paginated<Business>>('/businesses'), enabled: !!user })
}

/** The customer's workspace: their businesses and everything they do for them. */
export default function WorkspaceShell() {
  const { user, workspaceId, setWorkspace } = useAuth()
  const { pathname } = useLocation()
  const navigate = useNavigate()
  const [search, setSearch] = useState(false)
  useCommandShortcut(setSearch)
  const match = useMatch('/businesses/:id/*')
  const bid = match?.params.id && match.params.id !== 'new' ? match.params.id : null
  const list = useBusinessList()
  const businesses = useMemo(() => list.data?.data ?? [], [list.data])
  const current = businesses.find((b) => String(b.id) === bid)
  const workspace = user?.workspaces.find((w) => w.id === workspaceId)

  const commands = useMemo<Command[]>(() => {
    const out: Command[] = WORKSPACE_NAV.map((n) => ({ id: `ws${n.path}`, label: n.label, group: 'Workspace', to: n.path, icon: n.icon, keywords: n.keywords }))
    businesses.forEach((b) => out.push({ id: `b${b.id}`, label: b.name, group: 'Business', to: `/businesses/${b.id}`, icon: Building2, keywords: b.primary_category ?? '' }))
    if (bid) BUSINESS_NAV.forEach((g) => g.items.forEach((n) => out.push({ id: `s${n.path}`, label: n.label, group: current?.name ?? g.title, to: `/businesses/${bid}${n.path ? `/${n.path}` : ''}`, icon: n.icon, keywords: n.keywords })))
    return out
  }, [businesses, bid, current])

  if (!user) return pathname === '/' ? <Suspense fallback={null}><LandingPage /></Suspense> : <Navigate to="/login" replace />

  const section = bid ? null : WORKSPACE_NAV.find((n) => (n.end ? pathname === n.path : pathname.startsWith(n.path)))

  const sidebar = (
    <>
      <Brand />
      {user.workspaces.length > 1 && (
        <Switcher
          label="Workspace" current={workspace?.name ?? 'Choose a workspace'} icon={<LayoutGrid size={15} aria-hidden />}
          options={user.workspaces.map((w) => ({ id: w.id, label: w.name, hint: w.plan }))}
          onPick={(w) => { setWorkspace(Number(w.id)); navigate('/') }}
        />
      )}
      <nav className="side-nav">
        {bid ? (
          <>
            <Link to="/" className="side-back"><ArrowLeft size={15} aria-hidden /> All businesses</Link>
            {BUSINESS_NAV.map((g) => (
              <SideGroup key={g.title} title={g.title}>
                {g.items.map((n) => <SideLink key={n.path} item={n} to={`/businesses/${bid}${n.path ? `/${n.path}` : ''}`} />)}
              </SideGroup>
            ))}
            <SideGroup title="Workspace">
              {WORKSPACE_NAV.filter((n) => n.path !== '/').map((n) => <SideLink key={n.path} item={n} />)}
            </SideGroup>
          </>
        ) : (
          <SideGroup title="Workspace">
            {WORKSPACE_NAV.map((n) => <SideLink key={n.path} item={n} />)}
          </SideGroup>
        )}
      </nav>
      <UserCard extra={user.is_platform_admin && <Link to="/control" className="side-control"><ShieldHalf size={16} aria-hidden /> Platform Control</Link>} />
    </>
  )

  const topbar = (
    <div className="crumbs">
      <span className="crumb-ws">{workspace?.name ?? 'Workspace'}</span>
      <span className="crumb-sep" aria-hidden>/</span>
      {bid ? (
        <Switcher
          label="Business" current={current?.name ?? 'Business'} icon={<Building2 size={15} aria-hidden />}
          options={businesses.map((b) => ({ id: b.id, label: b.name, hint: b.primary_category ?? undefined }))}
          onPick={(b) => navigate(`/businesses/${b.id}${pathname.replace(/^\/businesses\/[^/]+/, '')}`)}
          footer={<Link to="/businesses/new" className="switcher-add"><Plus size={14} aria-hidden /> Add a business</Link>}
        />
      ) : <span className="crumb-page">{section?.label ?? 'Workspace'}</span>}
    </div>
  )

  return (
    <Frame variant="workspace" sidebar={sidebar} topbar={topbar} onSearch={() => setSearch(true)} searchLabel="Search">
      <VerifyBanner />
      <Outlet />
      <CommandPalette open={search} onClose={() => setSearch(false)} commands={commands} />
    </Frame>
  )
}
