import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type Business } from '../../api'
import { useAuth, useRole } from '../../auth'
import { Badge, Empty, ErrorState, Field, Spinner, StatusBadge, fieldErrors } from '../../ui'

interface Flag { severity: 'block' | 'warn'; kind: string; text: string; message: string }
interface ContentItem {
  id: number; type: string; type_label: string; use: string; max_chars: number; can_apply: boolean; title: string; status: string
  current_version: number; approved_version: number | null; review_note: string | null; applied_at: string | null
  body: string | null; generator: string | null; claim_flags: Flag[]; missing_facts: string[]
  versions?: { version: number; generator: string; created_at: string; flag_count: number }[]
}
interface ContentType { type: string; label: string; use: string; max_chars: number; needs: string | null; can_apply: boolean }
interface ListResponse { data: ContentItem[]; types: ContentType[]; ai_available: boolean }

function firstError(e: unknown): string | null {
  const errs = fieldErrors(e)
  const k = Object.keys(errs)[0]
  if (k) return errs[k][0]
  return e instanceof Error ? e.message : null
}

function Editor({ business, item, onChanged }: { business: Business; item: ContentItem; onChanged: () => void }) {
  const { canEdit, canApprove } = useRole()
  const [body, setBody] = useState(item.body ?? '')
  const [note, setNote] = useState('')
  useEffect(() => { setBody(item.body ?? '') }, [item.id, item.current_version, item.body])
  const base = `/businesses/${business.id}/content/${item.id}`
  const opts = { onSuccess: onChanged }
  const save = useMutation({ mutationFn: () => api(base, { method: 'PATCH', json: { body } }), ...opts })
  const submit = useMutation({ mutationFn: () => api(`${base}/submit`, { method: 'POST' }), ...opts })
  const decide = useMutation({ mutationFn: (decision: string) => api(`${base}/decision`, { method: 'POST', json: { decision, note: note || null } }), ...opts })
  const apply = useMutation({ mutationFn: () => api(`${base}/apply`, { method: 'POST' }), ...opts })
  const error = firstError(save.error ?? submit.error ?? decide.error ?? apply.error)
  const dirty = body !== (item.body ?? '')
  const blocks = item.claim_flags.filter((f) => f.severity === 'block')
  const editable = canEdit && item.status !== 'in_review'

  return (
    <div className="card stack">
      <div className="row between">
        <div>
          <h3 style={{ margin: 0 }}>{item.title}</h3>
          <p className="small muted" style={{ margin: 0 }}>{item.use}</p>
        </div>
        <div className="row"><StatusBadge status={item.status} /><Badge>v{item.current_version} · {item.generator === 'claude' ? 'AI draft' : item.generator === 'human' ? 'Edited' : 'Facts-only draft'}</Badge></div>
      </div>
      <textarea rows={10} value={body} onChange={(e) => setBody(e.target.value)} readOnly={!editable} aria-label="Content" />
      <div className="small muted">{body.length} / {item.max_chars} characters</div>
      {item.claim_flags.length > 0 && (
        <div className="stack" style={{ gap: '0.4rem' }}>
          <strong className="small">{blocks.length ? 'Fix before approval' : 'Please double-check'}</strong>
          {item.claim_flags.map((f, i) => <div key={i} className={`flag ${f.severity}`}><strong>{f.text}</strong>: {f.message}</div>)}
        </div>
      )}
      {item.review_note && <p className="notice warn" style={{ margin: 0 }}>Reviewer note: {item.review_note}</p>}
      {error && <p className="error-text">{error}</p>}
      <div className="row">
        {editable && <button className="btn ghost" disabled={!dirty || save.isPending} onClick={() => save.mutate()}>Save as new version</button>}
        {editable && ['draft', 'rejected'].includes(item.status) && <button className="btn primary" disabled={dirty || blocks.length > 0 || submit.isPending} onClick={() => submit.mutate()}>Send for approval</button>}
        {item.status === 'in_review' && canApprove && (
          <>
            <input placeholder="Note for the writer (optional)" value={note} onChange={(e) => setNote(e.target.value)} style={{ maxWidth: 280 }} />
            <button className="btn primary" disabled={decide.isPending} onClick={() => decide.mutate('approve')}>Approve</button>
            <button className="btn ghost danger" disabled={decide.isPending} onClick={() => decide.mutate('reject')}>Request changes</button>
          </>
        )}
        {item.status === 'in_review' && !canApprove && <span className="small muted">Waiting for an owner or admin to approve.</span>}
        {item.status === 'approved' && item.can_apply && canEdit && <button className="btn primary" disabled={apply.isPending} onClick={() => apply.mutate()}>{item.applied_at ? 'Use again as business description' : 'Use as business description'}</button>}
        {item.status === 'approved' && !item.can_apply && <button className="btn ghost" onClick={() => void navigator.clipboard?.writeText(item.body ?? '')}>Copy approved text</button>}
      </div>
    </div>
  )
}

export default function ContentTab({ business }: { business: Business }) {
  const { workspaceId } = useAuth()
  const { canEdit } = useRole()
  const qc = useQueryClient()
  const key = ['content', workspaceId, business.id]
  const q = useQuery({ queryKey: key, queryFn: () => api<ListResponse>(`/businesses/${business.id}/content`) })
  const services = useQuery({ queryKey: ['services', workspaceId, business.id], queryFn: () => api<{ data: { id: number; name: string }[] }>(`/businesses/${business.id}/services`) })
  const [type, setType] = useState('business_description')
  const [serviceId, setServiceId] = useState<number | ''>('')
  const [useAi, setUseAi] = useState(false)
  const [selected, setSelected] = useState<number | null>(null)
  const refresh = () => { void qc.invalidateQueries({ queryKey: key }); void qc.invalidateQueries({ queryKey: ['business', workspaceId, String(business.id)] }) }
  const create = useMutation({
    mutationFn: () => api<{ data: ContentItem }>(`/businesses/${business.id}/content`, { method: 'POST', json: { type, service_id: serviceId || null, use_ai: useAi } }),
    onSuccess: (r) => { setSelected(r.data.id); refresh() },
  })

  if (q.isLoading) return <Spinner />
  if (q.error) return <ErrorState error={q.error} retry={() => void q.refetch()} />
  const d = q.data!
  const current = d.data.find((c) => c.id === selected) ?? d.data[0]
  const spec = d.types.find((t) => t.type === type)

  return (
    <div className="stack">
      <p className="muted">Drafts written only from your approved business details. Missing details become [ADD: …] placeholders, never guesses. Every draft is checked for claims we can&apos;t back up, and an owner or admin approves it before use. Nothing is posted for you.</p>
      {canEdit && (
        <div className="card stack">
          <div className="grid" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '0.75rem' }}>
            <Field label="What to write" hint={spec?.use}>
              <select value={type} onChange={(e) => setType(e.target.value)}>
                {d.types.map((t) => <option key={t.type} value={t.type}>{t.label}</option>)}
              </select>
            </Field>
            {spec?.needs === 'service_id' && (
              <Field label="Service" error={fieldErrors(create.error).service_id}>
                <select value={serviceId} onChange={(e) => setServiceId(e.target.value ? Number(e.target.value) : '')}>
                  <option value="">Choose a service</option>
                  {services.data?.data.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
              </Field>
            )}
          </div>
          <div className="row between">
            {d.ai_available ? (
              <label className="row small" style={{ gap: '0.4rem' }}><input type="checkbox" style={{ width: 'auto' }} checked={useAi} onChange={(e) => setUseAi(e.target.checked)} /> Let AI polish the wording (same facts, same checks)</label>
            ) : <span className="small muted">Facts-only drafting. AI polishing can be switched on by the workspace owner.</span>}
            <button className="btn primary" disabled={create.isPending} onClick={() => create.mutate()}>{create.isPending ? 'Writing…' : 'Write draft'}</button>
          </div>
          {firstError(create.error) && !fieldErrors(create.error).service_id && <p className="error-text">{firstError(create.error)}</p>}
        </div>
      )}
      {d.data.length === 0 ? (
        <Empty title="No content yet">Start with “About your business”. It also improves your public page.</Empty>
      ) : (
        <>
          {d.data.length > 1 && (
            <div className="chips" role="group" aria-label="Drafts">
              {d.data.map((c) => <button key={c.id} aria-pressed={current?.id === c.id} onClick={() => setSelected(c.id)}>{c.title} · {c.status === 'approved' ? 'Approved' : c.status === 'in_review' ? 'In review' : 'Draft'}</button>)}
            </div>
          )}
          {current && <Editor key={current.id} business={business} item={current} onChanged={refresh} />}
        </>
      )}
    </div>
  )
}
