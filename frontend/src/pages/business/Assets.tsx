import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type Asset, type Business } from '../../api'
import { useAuth, useRole } from '../../auth'
import { Badge, Empty, ErrorState, Spinner, StatusBadge, fieldErrors, humanize } from '../../ui'

export default function AssetsTab({ business }: { business: Business }) {
  const { workspaceId } = useAuth()
  const { canEdit } = useRole()
  const qc = useQueryClient()
  const [url, setUrl] = useState('')
  const key = ['assets', workspaceId, business.id]
  const q = useQuery({
    queryKey: key,
    queryFn: () => api<{ data: Asset[] }>(`/businesses/${business.id}/assets`),
    refetchInterval: (query) => (query.state.data?.data.some((a) => a.scan_status === 'pending') ? 3000 : false),
  })
  const refresh = () => { void qc.invalidateQueries({ queryKey: key }); void qc.invalidateQueries({ queryKey: ['business', workspaceId, String(business.id)] }) }
  const add = useMutation({ mutationFn: () => api(`/businesses/${business.id}/assets`, { method: 'POST', json: { url } }), onSuccess: () => { setUrl(''); refresh() } })
  const rescan = useMutation({ mutationFn: (id: number) => api(`/businesses/${business.id}/assets/${id}/scan`, { method: 'POST' }), onSuccess: refresh })
  const remove = useMutation({ mutationFn: (id: number) => api(`/businesses/${business.id}/assets/${id}`, { method: 'DELETE' }), onSuccess: refresh })

  function submit(e: FormEvent) { e.preventDefault(); if (url.trim()) add.mutate() }

  return (
    <div className="stack">
      <p className="muted">Every place customers can find you: social profiles, storefronts, maps and directories. We check each public page and what it shows when shared.</p>
      {canEdit && (
        <form className="card row" onSubmit={submit} style={{ flexWrap: 'nowrap' }}>
          <input type="url" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="Paste a link: https://instagram.com/yourbusiness" aria-label="Profile link" />
          <button className="btn primary" disabled={add.isPending}>Add</button>
        </form>
      )}
      {fieldErrors(add.error).url && <p className="error-text">{fieldErrors(add.error).url[0]}</p>}
      {q.isLoading && <Spinner />}
      {q.error && <ErrorState error={q.error} />}
      {q.data?.data.length === 0 && <Empty title="No profiles yet">Add your Instagram, Facebook, TikTok, WhatsApp Business, Google Business Profile or marketplace links.</Empty>}
      {q.data && q.data.data.length > 0 && (
        <div className="card table-wrap">
          <table>
            <thead><tr><th>Platform</th><th>Link</th><th>Public check</th><th>What it shows</th><th>Connection</th><th /></tr></thead>
            <tbody>
              {q.data.data.map((a) => (
                <tr key={a.id}>
                  <td><Badge tone="brand">{humanize(a.platform)}</Badge></td>
                  <td style={{ wordBreak: 'break-all' }}><a href={a.url} target="_blank" rel="noreferrer noopener">{a.handle ? `@${a.handle}` : a.url}</a></td>
                  <td><StatusBadge status={a.scan_status} /></td>
                  <td className="small">{String(a.scan_data?.title ?? '') || <span className="muted">–</span>}</td>
                  <td className="small">{a.connection_state === 'not_connected' ? <span className="muted">Not connected</span> : humanize(a.connection_state)}</td>
                  <td>{canEdit && <div className="row">
                    <button className="btn ghost sm" onClick={() => rescan.mutate(a.id)}>Re-check</button>
                    <button className="btn ghost sm danger" onClick={() => remove.mutate(a.id)}>Remove</button>
                  </div>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <p className="small muted">Account connections (Google Search Console, Google Business Profile, Bing) arrive in a later release and always use the platform's own sign-in. We never ask for passwords.</p>
    </div>
  )
}
