import { useQuery } from '@tanstack/react-query'
import { api } from '../api'
import { useAuth } from '../auth'
import { ErrorState, Spinner, StatusBadge } from '../ui'

interface Stats { workspaces: number; businesses: number; businesses_without_website: number; crawl_runs: Record<string, number>; pages_crawled_30d: number }
interface Run { id: number; workspace_id: number; business_id: number; start_url: string; status: string; pages_checked: number; issues_found: number; error: string | null; created_at: string }
interface Log { id: number; action: string; target_type: string | null; target_id: number | null; workspace_id: number | null; created_at: string; actor: { name: string } | null }

export default function AdminPage() {
  const { user } = useAuth()
  const stats = useQuery({ queryKey: ['admin-stats'], queryFn: () => api<{ data: Stats }>('/admin/stats'), enabled: !!user?.is_platform_admin })
  const runs = useQuery({ queryKey: ['admin-runs'], queryFn: () => api<{ data: Run[] }>('/admin/crawl-runs'), enabled: !!user?.is_platform_admin })
  const logs = useQuery({ queryKey: ['admin-logs'], queryFn: () => api<{ data: Log[] }>('/admin/audit-logs'), enabled: !!user?.is_platform_admin })

  if (!user?.is_platform_admin) return <div className="card">This area is for Findriva operations staff.</div>
  return (
    <div className="stack">
      <h1>Operations</h1>
      {stats.isLoading && <Spinner />}
      {stats.error && <ErrorState error={stats.error} />}
      {stats.data && (
        <div className="grid">
          <div className="card stat"><div className="label">Workspaces</div><div className="value">{stats.data.data.workspaces}</div></div>
          <div className="card stat"><div className="label">Businesses</div><div className="value">{stats.data.data.businesses}</div><div className="small muted">{stats.data.data.businesses_without_website} without a website</div></div>
          <div className="card stat"><div className="label">Audits queued / running</div><div className="value">{stats.data.data.crawl_runs.queued} / {stats.data.data.crawl_runs.running}</div></div>
          <div className="card stat"><div className="label">Failed audits (7 days)</div><div className="value">{stats.data.data.crawl_runs.failed_7d}</div><div className="small muted">{stats.data.data.crawl_runs.completed_7d} completed</div></div>
        </div>
      )}
      <div className="card table-wrap">
        <h2>Recent audits</h2>
        <table>
          <thead><tr><th>#</th><th>Workspace</th><th>URL</th><th>Status</th><th>Pages</th><th>Issues</th><th>Error</th></tr></thead>
          <tbody>{runs.data?.data.map((r) => (
            <tr key={r.id}><td>{r.id}</td><td>{r.workspace_id}</td><td style={{ wordBreak: 'break-all' }}>{r.start_url}</td><td><StatusBadge status={r.status} /></td><td>{r.pages_checked}</td><td>{r.issues_found}</td><td className="small">{r.error}</td></tr>
          ))}</tbody>
        </table>
      </div>
      <div className="card table-wrap">
        <h2>Audit log</h2>
        <table>
          <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Target</th><th>Workspace</th></tr></thead>
          <tbody>{logs.data?.data.map((l) => (
            <tr key={l.id}><td className="small">{new Date(l.created_at).toLocaleString()}</td><td>{l.actor?.name ?? 'System'}</td><td><code>{l.action}</code></td><td className="small">{l.target_type} {l.target_id}</td><td>{l.workspace_id}</td></tr>
          ))}</tbody>
        </table>
      </div>
    </div>
  )
}
