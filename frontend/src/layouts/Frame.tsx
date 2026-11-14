import { useEffect, useRef, useState, type ReactNode } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { ChevronDown, LogOut, Menu, Search, X } from 'lucide-react'
import { useAuth } from '../auth'
import type { NavItem } from './nav'

/** Sidebar + top bar layout shared by the customer workspace and Platform Control. */
export function Frame({ variant, sidebar, topbar, onSearch, searchLabel, children }: {
  variant: 'workspace' | 'control'; sidebar: ReactNode; topbar: ReactNode; onSearch: () => void; searchLabel: string; children: ReactNode
}) {
  const [open, setOpen] = useState(false)
  const { pathname } = useLocation()
  useEffect(() => { setOpen(false) }, [pathname])
  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false) }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open])

  return (
    <div className={`app app-${variant} ${open ? 'nav-open' : ''}`}>
      <aside className="side" aria-label="Main navigation">
        <button className="side-close icon-btn" onClick={() => setOpen(false)} aria-label="Close menu"><X size={18} /></button>
        {sidebar}
      </aside>
      {open && <div className="side-scrim" onClick={() => setOpen(false)} aria-hidden />}
      <div className="app-main">
        <header className="topbar">
          <button className="icon-btn menu-btn" onClick={() => setOpen(true)} aria-label="Open menu"><Menu size={20} /></button>
          {topbar}
          <button className="search-btn" onClick={onSearch}>
            <Search size={16} aria-hidden /><span className="search-text">{searchLabel}</span><kbd>Ctrl K</kbd>
          </button>
        </header>
        <main className="content">{children}</main>
      </div>
    </div>
  )
}

export function Brand({ suffix }: { suffix?: string }) {
  return (
    <NavLink to={suffix ? '/control' : '/'} className="side-brand">
      <img src="/favicon.svg" alt="" width={28} height={28} />
      <span>Findriva{suffix && <small>{suffix}</small>}</span>
    </NavLink>
  )
}

export function SideLink({ item, to }: { item: NavItem; to?: string }) {
  return (
    <NavLink to={to ?? item.path} end={item.end} className="side-link">
      <item.icon size={17} strokeWidth={1.9} aria-hidden /><span>{item.label}</span>
    </NavLink>
  )
}

export function SideGroup({ title, children }: { title: string; children: ReactNode }) {
  return <div className="side-group"><div className="side-group-title">{title}</div>{children}</div>
}

export function UserCard({ extra }: { extra?: ReactNode }) {
  const { user, signOut } = useAuth()
  if (!user) return null
  const initials = user.name.split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase()
  return (
    <div className="side-user">
      {extra}
      <div className="side-user-row">
        <span className="avatar" aria-hidden>{initials}</span>
        <span className="side-user-text"><strong>{user.name}</strong><span>{user.email}</span></span>
        <button className="icon-btn" onClick={() => void signOut()} aria-label="Sign out" title="Sign out"><LogOut size={16} /></button>
      </div>
    </div>
  )
}

/** A small dropdown used for the business and workspace switchers. */
export function Switcher<T extends { id: number | string; label: string; hint?: string }>({ label, current, options, onPick, footer, icon }: {
  label: string; current: string; options: T[]; onPick: (o: T) => void; footer?: ReactNode; icon?: ReactNode
}) {
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)
  useEffect(() => {
    if (!open) return
    const close = (e: MouseEvent) => { if (!ref.current?.contains(e.target as Node)) setOpen(false) }
    const key = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false) }
    document.addEventListener('mousedown', close)
    window.addEventListener('keydown', key)
    return () => { document.removeEventListener('mousedown', close); window.removeEventListener('keydown', key) }
  }, [open])
  return (
    <div className="switcher" ref={ref}>
      <button className="switcher-btn" aria-haspopup="listbox" aria-expanded={open} aria-label={label} onClick={() => setOpen((v) => !v)}>
        {icon}<span className="switcher-current">{current}</span><ChevronDown size={15} aria-hidden />
      </button>
      {open && (
        <div className="switcher-menu" role="listbox" aria-label={label}>
          {options.map((o) => (
            <button key={o.id} role="option" aria-selected={o.label === current} className={o.label === current ? 'on' : ''} onClick={() => { setOpen(false); onPick(o) }}>
              <span>{o.label}</span>{o.hint && <small>{o.hint}</small>}
            </button>
          ))}
          {footer && <div className="switcher-footer" onClick={() => setOpen(false)}>{footer}</div>}
        </div>
      )}
    </div>
  )
}
