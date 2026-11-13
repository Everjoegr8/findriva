import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api, type Business } from '../../api'
import { useAuth } from '../../auth'
import { Badge, ErrorState, Spinner, humanize } from '../../ui'

interface Fix {
  key: string; title: string; summary: string; filename: string | null; format: string; content: string
  instructions: string[]; resolves: string[]; open_issue_codes: string[]; missing_facts: { key: string; label: string }[]
  schema_type?: string; page_count?: number
}

function download(name: string, content: string, type: string) {
  const url = URL.createObjectURL(new Blob([content], { type }))
  const a = document.createElement('a')
  a.href = url
  a.download = name
  a.click()
  URL.revokeObjectURL(url)
}

function FixCard({ fix }: { fix: Fix }) {
  const [copied, setCopied] = useState(false)
  async function copy() {
    try { await navigator.clipboard.writeText(fix.content); setCopied(true); setTimeout(() => setCopied(false), 1500) } catch { setCopied(false) }
  }
  return (
    <div className="card stack">
      <div className="row between">
        <div>
          <h3 style={{ margin: 0 }}>{fix.title}</h3>
          <p className="small muted" style={{ margin: 0 }}>{fix.summary}</p>
        </div>
        {fix.open_issue_codes.length > 0 ? <Badge tone="high">Fixes {fix.open_issue_codes.length} open issue{fix.open_issue_codes.length > 1 ? 's' : ''}</Badge> : <Badge>Optional</Badge>}
      </div>
      {fix.missing_facts.length > 0 && (
        <p className="notice warn" style={{ margin: 0 }}>This gets better if you add {fix.missing_facts.map((m) => m.label).join(', ')} in Business profile.</p>
      )}
      <pre className="code" aria-label={`${fix.title} content`}>{fix.content}</pre>
      <div className="row">
        <button className="btn primary sm" onClick={() => void copy()}>{copied ? 'Copied' : 'Copy'}</button>
        {fix.filename && <button className="btn ghost sm" onClick={() => download(fix.filename!, fix.content, fix.format === 'xml' ? 'application/xml' : 'text/plain')}>Download {fix.filename}</button>}
        {fix.schema_type && <span className="small muted">Business type: {fix.schema_type}</span>}
        {fix.page_count !== undefined && <span className="small muted">{fix.page_count} page{fix.page_count === 1 ? '' : 's'} listed</span>}
      </div>
      <ol className="small" style={{ margin: 0, paddingLeft: '1.2rem' }}>
        {fix.instructions.map((i) => <li key={i}>{i}</li>)}
      </ol>
      {fix.open_issue_codes.length > 0 && <p className="small muted" style={{ margin: 0 }}>Related issues: {fix.open_issue_codes.map(humanize).join(', ')}</p>}
    </div>
  )
}

export default function FixesTab({ business }: { business: Business }) {
  const { workspaceId } = useAuth()
  const q = useQuery({ queryKey: ['fixes', workspaceId, business.id], queryFn: () => api<{ data: Fix[]; has_website: boolean }>(`/businesses/${business.id}/fixes`) })
  if (q.isLoading) return <Spinner />
  if (q.error) return <ErrorState error={q.error} retry={() => void q.refetch()} />
  return (
    <div className="stack">
      <p className="muted">Ready-to-paste files and code, written only from your approved business details. Findriva never changes your site for you. After you apply a fix, run a new audit and we will confirm it worked.</p>
      {!q.data?.has_website && <p className="notice">You don&apos;t have a website yet. Your Findriva public page can act as one, and it already includes the structured data below.</p>}
      {q.data?.data.map((f) => <FixCard key={f.key} fix={f} />)}
    </div>
  )
}
