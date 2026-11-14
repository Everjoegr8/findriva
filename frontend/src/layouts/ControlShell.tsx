import { useMemo, useState } from 'react'
import { Link, Navigate, useLocation } from 'react-router-dom'
import { ArrowLeftRight, ShieldHalf } from 'lucide-react'
import { useAuth } from '../auth'
import { CommandPalette, useCommandShortcut, type Command } from '../components/CommandPalette'
import { Brand, Frame, SideGroup, SideLink, UserCard } from './Frame'
import { CONTROL_NAV } from './nav'
import AdminRoutes from '../pages/Admin'

/** Platform Control: Findriva staff only. A separate shell so it never mixes with a customer's workspace. */
export default function ControlShell() {
  const { user } = useAuth()
  const { pathname } = useLocation()
  const [search, setSearch] = useState(false)
  useCommandShortcut(setSearch)
  const commands = useMemo<Command[]>(() => CONTROL_NAV.flatMap((g) => g.items.map((n) => ({ id: n.path, label: n.label, group: g.title, to: n.path, icon: n.icon, keywords: n.keywords }))), [])

  if (!user) return <Navigate to="/login" replace />
  if (!user.is_platform_admin) return <Navigate to="/" replace />

  const all = CONTROL_NAV.flatMap((g) => g.items)
  const section = [...all].reverse().find((n) => (n.end ? pathname === n.path : pathname.startsWith(n.path)))

  const sidebar = (
    <>
      <Brand suffix="Platform Control" />
      <nav className="side-nav">
        {CONTROL_NAV.map((g) => (
          <SideGroup key={g.title} title={g.title}>
            {g.items.map((n) => <SideLink key={n.path} item={n} />)}
          </SideGroup>
        ))}
      </nav>
      <UserCard extra={<Link to="/" className="side-control"><ArrowLeftRight size={16} aria-hidden /> Open my workspace</Link>} />
    </>
  )
  const topbar = (
    <div className="crumbs">
      <span className="staff-pill"><ShieldHalf size={14} aria-hidden /> Staff only</span>
      <span className="crumb-sep" aria-hidden>/</span>
      <span className="crumb-page">{section?.label ?? 'Platform Control'}</span>
    </div>
  )
  return (
    <Frame variant="control" sidebar={sidebar} topbar={topbar} onSearch={() => setSearch(true)} searchLabel="Jump to">
      <AdminRoutes />
      <CommandPalette open={search} onClose={() => setSearch(false)} commands={commands} placeholder="Jump to a Platform Control page" />
    </Frame>
  )
}
