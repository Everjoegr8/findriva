import { useRef, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, CircleAlert, CircleDashed, ExternalLink, Loader2, RefreshCw, Trash2, Upload } from 'lucide-react'
import { api, type Asset, type Business } from '../../api'
import { useAuth, useRole } from '../../auth'
import { Empty, ErrorState, Spinner, fieldErrors, humanize } from '../../ui'

const PLATFORM_LABEL: Record<string, string> = {
  google_business_profile: 'Google Business Profile', bing_places: 'Bing Places', apple_business_connect: 'Apple Business Connect',
  whatsapp: 'WhatsApp', tiktok: 'TikTok', youtube: 'YouTube', linkedin: 'LinkedIn', x: 'X (Twitter)',
}

/** One clear state per profile, from the last public check. */
function state(a: Asset): { tone: string; label: string; icon: typeof CheckCircle2; hint: string } {
  if (a.scan_status === 'pending') return { tone: 'pending', label: 'Checking', icon: Loader2, hint: 'Opening the public page now.' }
  if (a.scan_status === 'unreachable') return { tone: 'bad', label: 'Not reachable', icon: CircleAlert, hint: 'The link did not open for the public. Check it is correct and public.' }
  if (a.scan_status === 'limited') return { tone: 'warn', label: 'Partly visible', icon: CircleDashed, hint: 'The page asks visitors to log in, so search engines see little of it.' }
  return { tone: 'ok', label: 'Live and public', icon: CheckCircle2, hint: 'Anyone can open this page.' }
}

interface ImportResult { added: number; skipped: { url: string; reason: string }[] }

export default function AssetsTab({ business }: { business: Business }) {
  const { workspaceId } = useAuth()
  const { canEdit } = useRole()
  const qc = useQueryClient()
  const [url, setUrl] = useState('')
  const [showImport, setShowImport] = useState(false)
  const [text, setText] = useState('')
  const [result, setResult] = useState<ImportResult | null>(null)
  const fileRef = useRef<HTMLInputElement>(null)
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
  const importer = useMutation({
    mutationFn: (body: FormData) => api<{ data: ImportResult }>(`/businesses/${business.id}/assets/import`, { method: 'POST', body }),
    onSuccess: (r) => { setResult(r.data); setText(''); if (fileRef.current) fileRef.current.value = ''; refresh() },
  })

  function submit(e: FormEvent) { e.preventDefault(); if (url.trim()) add.mutate() }
  function runImport(e: FormEvent) {
    e.preventDefault(); setResult(null)
    const fd = new FormData()
    const file = fileRef.current?.files?.[0]
    if (file) fd.append('file', file)
    else fd.append('text', text)
    importer.mutate(fd)
  }

  const assets = q.data?.data ?? []
  const counts = { ok: assets.filter((a) => state(a).tone === 'ok').length, attention: assets.filter((a) => ['bad', 'warn'].includes(state(a).tone)).length }

  return (
    <div className="stack">
      <p className="muted" style={{ margin: 0 }}>Every place customers can find you: social profiles, storefronts, maps and directories. We open each public page and record what it shows.</p>

      {assets.length > 0 && (
        <div className="usage-strip" style={{ gridTemplateColumns: 'repeat(3, minmax(0, 1fr))' }}>
          <div><span className="muted small">Profiles listed</span><strong>{assets.length}</strong></div>
          <div><span className="muted small">Live and public</span><strong>{counts.ok}</strong></div>
          <div><span className="muted small">Need attention</span><strong>{counts.attention}</strong></div>
        </div>
      )}

      {canEdit && (
        <div className="card stack" style={{ gap: '0.75rem' }}>
          <form className="row" onSubmit={submit} style={{ flexWrap: 'nowrap' }}>
            <input type="url" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="Paste a link: https://instagram.com/yourbusiness" aria-label="Profile link" />
            <button className="btn primary" disabled={add.isPending}>Add</button>
            <button type="button" className="btn" onClick={() => setShowImport((v) => !v)} aria-expanded={showImport}><Upload size={15} aria-hidden /> Import</button>
          </form>
          {fieldErrors(add.error).url && <p className="error-text" style={{ margin: 0 }}>{fieldErrors(add.error).url[0]}</p>}
          {showImport && (
            <form className="import-box stack" onSubmit={runImport}>
              <div className="small muted">Add up to 50 links at once. Upload a CSV or text file (any column with links works), or paste one link per line.</div>
              <input ref={fileRef} type="file" accept=".csv,.txt,text/csv,text/plain" aria-label="CSV or text file" />
              <textarea rows={4} value={text} onChange={(e) => setText(e.target.value)} placeholder={'https://instagram.com/yourbusiness\nhttps://www.tiktok.com/@yourbusiness'} aria-label="Links, one per line" />
              <div className="row">
                <button className="btn primary sm" disabled={importer.isPending}>{importer.isPending ? 'Importing…' : 'Import links'}</button>
                {importer.error ? <span className="error-text">{fieldErrors(importer.error).file?.[0] ?? fieldErrors(importer.error).text?.[0] ?? (importer.error as Error).message}</span> : null}
              </div>
              {result && (
                <div className="notice small" role="status">
                  Added {result.added} link{result.added === 1 ? '' : 's'}.{result.skipped.length > 0 && ` Skipped ${result.skipped.length}:`}
                  {result.skipped.length > 0 && <ul style={{ margin: '0.3rem 0 0', paddingLeft: '1.1rem' }}>{result.skipped.map((s) => <li key={s.url} style={{ wordBreak: 'break-all' }}>{s.url}: {s.reason}</li>)}</ul>}
                </div>
              )}
            </form>
          )}
        </div>
      )}

      {q.isLoading && <Spinner />}
      {q.error && <ErrorState error={q.error} />}
      {q.data?.data.length === 0 && <Empty title="No profiles yet">Add your Instagram, Facebook, TikTok, WhatsApp Business, Google Business Profile or marketplace links.</Empty>}
      {assets.length > 0 && (
        <div className="asset-grid">
          {assets.map((a) => {
            const st = state(a)
            const title = String(a.scan_data?.title ?? '')
            return (
              <div key={a.id} className={`card asset-card ${st.tone}`}>
                <div className="asset-head">
                  <span className="asset-platform">{PLATFORM_LABEL[a.platform] ?? humanize(a.platform)}</span>
                  <span className={`asset-state ${st.tone}`} title={st.hint}><st.icon size={14} aria-hidden className={st.tone === 'pending' ? 'spin' : ''} /> {st.label}</span>
                </div>
                <a href={a.url} target="_blank" rel="noreferrer noopener" className="asset-link">{a.handle ? `@${a.handle}` : a.url} <ExternalLink size={13} aria-hidden /></a>
                <div className="small muted asset-title">{title || st.hint}</div>
                <div className="asset-foot small">
                  <span className="muted">{a.ownership_status === 'claimed' ? 'Yours' : humanize(a.ownership_status)} · {a.connection_state === 'not_connected' ? 'Not connected' : humanize(a.connection_state)}</span>
                  {canEdit && (
                    <span className="row" style={{ gap: '0.15rem' }}>
                      <button className="icon-btn" title="Check again" aria-label={`Check ${a.url} again`} onClick={() => rescan.mutate(a.id)}><RefreshCw size={15} /></button>
                      <button className="icon-btn danger" title="Remove" aria-label={`Remove ${a.url}`} onClick={() => remove.mutate(a.id)}><Trash2 size={15} /></button>
                    </span>
                  )}
                </div>
              </div>
            )
          })}
        </div>
      )}
      <p className="small muted" style={{ margin: 0 }}>Account connections (Google Search Console, Google Business Profile, Bing) always use the platform's own sign-in. We never ask for passwords.</p>
    </div>
  )
}
