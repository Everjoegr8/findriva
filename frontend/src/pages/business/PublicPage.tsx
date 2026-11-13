import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type Business } from '../../api'
import { useAuth } from '../../auth'
import { Badge, ErrorState, Spinner, fieldErrors } from '../../ui'

interface Publication {
  is_published: boolean; slug: string | null; suggested_slug: string; url: string | null; published_at: string | null
  gate: { ready: boolean; checks: { key: string; label: string; ok: boolean }[] }; can_publish: boolean
}

export default function PublicPageTab({ business }: { business: Business }) {
  const { workspaceId } = useAuth()
  const qc = useQueryClient()
  const key = ['public-page', workspaceId, business.id]
  const q = useQuery({ queryKey: key, queryFn: () => api<{ data: Publication }>(`/businesses/${business.id}/public-page`) })
  const [slug, setSlug] = useState<string | null>(null)
  const done = (r: { data: Publication }) => { qc.setQueryData(key, r); void qc.invalidateQueries({ queryKey: ['fixes', workspaceId, business.id] }) }
  const publish = useMutation({ mutationFn: () => api<{ data: Publication }>(`/businesses/${business.id}/public-page`, { method: 'POST', json: { slug: slug ?? q.data?.data.suggested_slug } }), onSuccess: done })
  const unpublish = useMutation({ mutationFn: () => api<{ data: Publication }>(`/businesses/${business.id}/public-page`, { method: 'DELETE' }), onSuccess: done })

  if (q.isLoading) return <Spinner />
  if (q.error) return <ErrorState error={q.error} retry={() => void q.refetch()} />
  const p = q.data!.data
  const errs = fieldErrors(publish.error)

  return (
    <div className="stack">
      <p className="muted">A fast, mobile-friendly one-page website built from your business profile, with your contact buttons, services, hours and the structured data search engines and AI assistants read. Ideal if you only sell on Instagram or WhatsApp.</p>
      <div className="card stack">
        <div className="row between">
          <h3 style={{ margin: 0 }}>Your public page</h3>
          {p.is_published ? <Badge tone="ok">Live</Badge> : <Badge>Not published</Badge>}
        </div>
        {p.is_published && p.url && (
          <p style={{ margin: 0 }}><a href={p.url} target="_blank" rel="noreferrer noopener">{p.url}</a></p>
        )}
        <div>
          <strong className="small">Before it can go live</strong>
          <ul className="check-list small" style={{ marginTop: '0.4rem' }}>
            {p.gate.checks.map((c) => <li key={c.key} className={c.ok ? 'ok' : ''}>{c.label}</li>)}
          </ul>
        </div>
        {p.can_publish ? (
          <div className="stack">
            <label className="small">
              Page address
              <div className="row" style={{ flexWrap: 'nowrap', alignItems: 'center' }}>
                <span className="muted small" style={{ whiteSpace: 'nowrap' }}>/b/</span>
                <input value={slug ?? p.slug ?? p.suggested_slug} onChange={(e) => setSlug(e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-'))} aria-label="Page address" />
              </div>
            </label>
            {(errs.slug || errs.business) && <p className="error-text">{(errs.slug ?? errs.business)[0]}</p>}
            <div className="row">
              <button className="btn primary" disabled={!p.gate.ready || publish.isPending} onClick={() => publish.mutate()}>{p.is_published ? 'Update page' : 'Publish page'}</button>
              {p.is_published && <button className="btn ghost danger" disabled={unpublish.isPending} onClick={() => unpublish.mutate()}>Take page offline</button>}
            </div>
            <p className="small muted" style={{ margin: 0 }}>Street addresses you marked private are never shown. Publishing makes the page visible to anyone and to search engines; it does not guarantee a ranking.</p>
          </div>
        ) : (
          <p className="small muted" style={{ margin: 0 }}>Only an owner or admin can publish this page.</p>
        )}
      </div>
    </div>
  )
}
