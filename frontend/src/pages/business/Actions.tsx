import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type Action, type ActionStatus, type Paginated } from '../../api'
import { useAuth, useRole } from '../../auth'
import { Badge, Empty, ErrorState, SeverityBadge, Spinner, StatusBadge, humanize } from '../../ui'

const FILTERS: Record<string, string> = {
  active: 'open,awaiting_approval,approved,in_progress,blocked',
  done: 'done,verified',
  all: '',
}
const NEXT_LABEL: Partial<Record<ActionStatus, string>> = {
  in_progress: 'Start', done: 'Mark done', blocked: 'Blocked', open: 'Reopen', dismissed: 'Dismiss',
}

export default function ActionsTab({ businessId }: { businessId: number }) {
  const { workspaceId } = useAuth()
  const { canEdit, canApprove } = useRole()
  const qc = useQueryClient()
  const [filter, setFilter] = useState<keyof typeof FILTERS>('active')
  const key = ['actions', workspaceId, businessId, filter]
  const q = useQuery({
    queryKey: key,
    queryFn: () => api<Paginated<Action>>(`/businesses/${businessId}/actions?per_page=100${FILTERS[filter] ? `&status=${FILTERS[filter]}` : ''}`),
  })

  const mutate = useMutation({
    mutationFn: ({ action, kind, status }: { action: Action; kind: 'status' | 'request' | 'approve' | 'reject'; status?: ActionStatus }) => {
      const base = `/businesses/${businessId}/actions/${action.id}`
      if (kind === 'request') return api(`${base}/request-approval`, { method: 'POST' })
      if (kind === 'approve' || kind === 'reject') return api(`${base}/decision`, { method: 'POST', json: { decision: kind } })
      return api(base, { method: 'PATCH', json: { status } })
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['actions', workspaceId, businessId] })
      void qc.invalidateQueries({ queryKey: ['overview', workspaceId, businessId] })
    },
  })

  return (
    <div className="stack">
      <div className="row between">
        <p className="muted" style={{ margin: 0 }}>Your to-do list, most important first. Changes to your website or profiles always wait for your approval.</p>
        <div className="row">
          {Object.keys(FILTERS).map((f) => (
            <button key={f} className={`btn sm ${filter === f ? 'primary' : ''}`} onClick={() => setFilter(f as keyof typeof FILTERS)}>{humanize(f)}</button>
          ))}
        </div>
      </div>
      {mutate.error && <ErrorState error={mutate.error} />}
      {q.isLoading && <Spinner />}
      {q.error && <ErrorState error={q.error} retry={() => void q.refetch()} />}
      {q.data?.data.length === 0 && <Empty title="Nothing here">{filter === 'active' ? 'No open actions. Run a new audit to check for anything new.' : null}</Empty>}
      {q.data?.data.map((a) => (
        <div key={a.id} className={`card issue ${a.issue?.severity ?? ''}`}>
          <div className="row between">
            <div className="row">
              {a.issue && <SeverityBadge severity={a.issue.severity} />}
              <strong>{a.title}</strong>
            </div>
            <StatusBadge status={a.status} />
          </div>
          <dl className="qa">
            {a.issue && <><dt>Why it matters</dt><dd>{a.issue.why_it_matters}</dd></>}
            <dt>What to do</dt><dd>{a.description}</dd>
            {a.access_needed && <><dt>Access needed</dt><dd>{a.access_needed}</dd></>}
            {a.issue && <><dt>How we'll verify</dt><dd>{a.issue.verification_method}</dd></>}
            {a.verification && <><dt>Verified</dt><dd>{a.verification.result} <span className="muted small">{new Date(a.verification.checked_at).toLocaleString()}</span></dd></>}
          </dl>
          <div className="row small muted" style={{ marginTop: '0.5rem' }}>
            <span>Impact: {a.impact}</span><span>Effort: {a.effort}</span>
            {a.requires_approval && <Badge>{a.approved_at ? 'Approved' : 'Needs approval'}</Badge>}
          </div>
          {canEdit && (
            <div className="row" style={{ marginTop: '0.75rem' }}>
              {a.status === 'awaiting_approval' && canApprove && (
                <>
                  <button className="btn primary sm" disabled={mutate.isPending} onClick={() => mutate.mutate({ action: a, kind: 'approve' })}>Approve</button>
                  <button className="btn sm" disabled={mutate.isPending} onClick={() => mutate.mutate({ action: a, kind: 'reject' })}>Reject</button>
                </>
              )}
              {a.requires_approval && !a.approved_at && a.allowed_next.includes('awaiting_approval') && (
                <button className="btn sm" disabled={mutate.isPending} onClick={() => mutate.mutate({ action: a, kind: 'request' })}>Ask for approval</button>
              )}
              {a.allowed_next
                .filter((s) => NEXT_LABEL[s] && !(a.requires_approval && !a.approved_at && (s === 'in_progress' || s === 'done')))
                .map((s) => (
                  <button key={s} className={`btn sm ${s === 'dismissed' ? 'ghost' : ''}`} disabled={mutate.isPending} onClick={() => mutate.mutate({ action: a, kind: 'status', status: s })}>{NEXT_LABEL[s]}</button>
                ))}
            </div>
          )}
        </div>
      ))}
    </div>
  )
}
