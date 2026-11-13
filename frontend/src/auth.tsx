import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { api, session, type User } from './api'

interface AuthState {
  user: User | null
  loading: boolean
  workspaceId: number | null
  setWorkspace: (id: number) => void
  signIn: (token: string, user: User) => void
  signOut: () => Promise<void>
  refresh: () => Promise<void>
}

const Ctx = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const qc = useQueryClient()
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(!!session.token)
  const [workspaceId, setWorkspaceId] = useState<number | null>(session.workspaceId ? Number(session.workspaceId) : null)

  const adopt = useCallback((u: User) => {
    setUser(u)
    const stored = session.workspaceId ? Number(session.workspaceId) : null
    const valid = u.workspaces.some((w) => w.id === stored) ? stored : (u.current_workspace_id ?? u.workspaces[0]?.id ?? null)
    session.workspaceId = valid ? String(valid) : null
    setWorkspaceId(valid)
  }, [])

  const refresh = useCallback(async () => {
    if (!session.token) { setLoading(false); return }
    try {
      const { user } = await api<{ user: User }>('/auth/me')
      adopt(user)
    } catch {
      session.token = null
      setUser(null)
    } finally {
      setLoading(false)
    }
  }, [adopt])

  useEffect(() => { void refresh() }, [refresh])

  const value: AuthState = {
    user, loading, workspaceId,
    setWorkspace: (id) => { session.workspaceId = String(id); setWorkspaceId(id); qc.clear() },
    signIn: (token, u) => { session.token = token; adopt(u) },
    signOut: async () => {
      try { await api('/auth/logout', { method: 'POST' }) } catch { /* already signed out */ }
      session.token = null; session.workspaceId = null; setUser(null); qc.clear()
    },
    refresh,
  }
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>
}

export function useAuth() {
  const v = useContext(Ctx)
  if (!v) throw new Error('useAuth outside AuthProvider')
  return v
}

export function useRole() {
  const { user, workspaceId } = useAuth()
  const role = user?.workspaces.find((w) => w.id === workspaceId)?.role
  return {
    role,
    canEdit: role === 'owner' || role === 'admin' || role === 'editor',
    canApprove: role === 'owner' || role === 'admin',
  }
}
