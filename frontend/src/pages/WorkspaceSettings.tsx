import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Sparkles } from 'lucide-react'
import { api } from '../api'
import { useAuth, useRole } from '../auth'
import { ErrorState, Field, PageTitle, Spinner, Toggle, fieldErrors } from '../ui'

interface WorkspaceInfo { id: number; name: string; settings: { ai_enabled?: boolean } | null }

export default function WorkspaceSettingsPage() {
  const { workspaceId, refresh } = useAuth()
  const { canApprove } = useRole()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['workspace', workspaceId], queryFn: () => api<{ data: WorkspaceInfo }>('/workspace').then((r) => r.data) })
  const [name, setName] = useState('')
  const [msg, setMsg] = useState<string | null>(null)
  useEffect(() => { if (q.data) setName(q.data.name) }, [q.data])
  const save = useMutation({
    mutationFn: (json: Record<string, unknown>) => api('/workspace', { method: 'PATCH', json }),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['workspace', workspaceId] }); void refresh(); setMsg('Saved.') },
  })
  if (q.isLoading) return <Spinner />
  if (q.error) return <ErrorState error={q.error} retry={() => void q.refetch()} />
  const ai = q.data!.settings?.ai_enabled ?? true

  return (
    <div className="stack" style={{ maxWidth: 760 }}>
      <PageTitle title="Workspace settings" sub={canApprove ? 'Settings for everyone in this workspace. Changes are recorded in the activity log.' : 'Only owners and admins can change these settings.'} />
      <form className="card stack" onSubmit={(e) => { e.preventDefault(); setMsg(null); save.mutate({ name }) }}>
        <Field label="Workspace name" error={fieldErrors(save.error).name}>
          <input value={name} onChange={(e) => setName(e.target.value)} maxLength={120} disabled={!canApprove} />
        </Field>
        {canApprove && <div><button className="btn primary" disabled={save.isPending || name === q.data!.name}>Save name</button></div>}
      </form>
      <div className="card stack">
        <div className="toggle-row" style={{ padding: 0, border: 0 }}>
          <span className="ai-badge" aria-hidden><Sparkles size={18} /></span>
          <div>
            <strong>AI features</strong>
            <span className="small muted">
              AI drafting in the Content Studio and AI answer checks. When this is off, drafts are written only from your approved business details,
              and no questions are sent to an AI assistant. Everything else works the same, and nothing AI writes is ever published without your approval.
            </span>
          </div>
          <Toggle checked={ai} label="AI features on" disabled={!canApprove || save.isPending} onChange={(v) => { setMsg(null); save.mutate({ settings: { ai_enabled: v } }) }} />
        </div>
      </div>
      {msg && <p className="small" style={{ color: 'var(--ok)', margin: 0 }} role="status">{msg}</p>}
      {save.error && !Object.keys(fieldErrors(save.error)).length ? <ErrorState error={save.error} /> : null}
    </div>
  )
}
