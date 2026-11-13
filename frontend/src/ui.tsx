import type { ReactNode } from 'react'
import { ApiError } from './api'

export function Spinner({ label = 'Loading' }: { label?: string }) {
  return <span className="row muted"><span className="spinner" aria-hidden /> {label}…</span>
}

export function ErrorState({ error, retry }: { error: unknown; retry?: () => void }) {
  const msg = error instanceof Error ? error.message : 'Something went wrong.'
  return (
    <div className="card notice warn" role="alert">
      <div className="row between"><span>{msg}</span>{retry && <button className="btn sm" onClick={retry}>Try again</button>}</div>
    </div>
  )
}

export function Empty({ title, children }: { title: string; children?: ReactNode }) {
  return <div className="card empty"><h3>{title}</h3>{children}</div>
}

export function Badge({ tone, children }: { tone?: string; children: ReactNode }) {
  return <span className={`badge ${tone ?? ''}`}>{children}</span>
}

const STATUS_LABEL: Record<string, [string, string]> = {
  open: ['To do', ''], awaiting_approval: ['Awaiting approval', 'medium'], approved: ['Approved', 'brand'],
  in_progress: ['In progress', 'brand'], blocked: ['Blocked', 'high'], done: ['Done, checking', 'low'],
  verified: ['Verified', 'ok'], dismissed: ['Dismissed', ''], resolved: ['Resolved', 'ok'],
  queued: ['Queued', ''], running: ['Running', 'brand'], completed: ['Completed', 'ok'], failed: ['Failed', 'critical'], cancelled: ['Cancelled', ''],
  draft: ['Draft', ''], in_review: ['Waiting for review', 'medium'], rejected: ['Changes requested', 'high'],
  suggested: ['Suggested', ''], tracked: ['Tracking', 'brand'],
  ok: ['Reachable', 'ok'], limited: ['Limited (login wall)', 'medium'], unreachable: ['Unreachable', 'critical'], pending: ['Checking', ''],
}
export function StatusBadge({ status }: { status: string }) {
  const [label, tone] = STATUS_LABEL[status] ?? [status, '']
  return <Badge tone={tone}>{label}</Badge>
}

export function SeverityBadge({ severity }: { severity: string }) {
  const label = { critical: 'Urgent', high: 'High', medium: 'Medium', low: 'Low', info: 'Info' }[severity] ?? severity
  return <Badge tone={severity}>{label}</Badge>
}

export function Field({ label, hint, error, children }: { label: string; hint?: string; error?: string[]; children: ReactNode }) {
  return (
    <label className="field">
      <span>{label}</span>
      {hint && <span className="hint">{hint}</span>}
      {children}
      {error?.length ? <span className="error-text">{error[0]}</span> : null}
    </label>
  )
}

export function fieldErrors(error: unknown): Record<string, string[]> {
  return error instanceof ApiError ? error.errors : {}
}

export function Meter({ value }: { value: number }) {
  return <div className="meter" role="meter" aria-valuenow={value} aria-valuemin={0} aria-valuemax={100}><span style={{ width: `${Math.max(0, Math.min(100, value))}%` }} /></div>
}

export function humanize(s: string) {
  return s.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase())
}
