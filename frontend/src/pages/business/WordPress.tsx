import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api, type Business } from '../../api'
import { useAuth } from '../../auth'
import { Badge, ErrorState, Spinner, fieldErrors, humanize } from '../../ui'

interface Connector {
  id: number; status: string; site_url: string | null; plugin_version: string | null; wp_version: string | null
  last_seen_at: string | null; pairing_expires_at: string | null; published_items: string[]; published_at: string | null; published_version: string | null
}
interface Manifest { version: string | null; homepage: { jsonld?: string; meta?: Record<string, string> } }
interface WordPressData {
  connector: Connector | null; items: { key: string; label: string }[]; preview: Manifest; website: string | null
  plugin: { version: string; download_url: string }; can_manage: boolean; outdated: boolean
}

function when(iso: string | null) {
  return iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : 'never'
}

function previewText(m: Manifest, items: string[]) {
  const lines: string[] = []
  if (items.includes('meta') && m.homepage.meta) {
    for (const [k, v] of Object.entries(m.homepage.meta)) {
      lines.push(k.startsWith('og:') ? `<meta property="${k}" content="${v}">` : `<meta name="${k}" content="${v}">`)
    }
  }
  if (items.includes('jsonld') && m.homepage.jsonld) {
    lines.push('<script type="application/ld+json">', JSON.stringify(JSON.parse(m.homepage.jsonld), null, 2), '</script>')
  }
  return lines.join('\n')
}

export default function WordPressTab({ business }: { business: Business }) {
  const { workspaceId } = useAuth()
  const qc = useQueryClient()
  const key = ['wordpress', workspaceId, business.id]
  const q = useQuery({
    queryKey: key,
    queryFn: () => api<{ data: WordPressData }>(`/businesses/${business.id}/wordpress`),
    // While a code is waiting to be used, check for the plugin calling in.
    refetchInterval: (query) => (query.state.data?.data.connector?.status === 'pending' ? 4000 : false),
  })
  const refresh = () => qc.invalidateQueries({ queryKey: key })
  const [code, setCode] = useState<{ code: string; expires_at: string } | null>(null)
  const [chosen, setChosen] = useState<string[] | null>(null)
  const pairing = useMutation({
    mutationFn: () => api<{ data: { code: string; expires_at: string } }>(`/businesses/${business.id}/wordpress/pairing-code`, { method: 'POST' }),
    onSuccess: (r) => { setCode(r.data); refresh() },
  })
  const publish = useMutation({
    mutationFn: (items: string[]) => api(`/businesses/${business.id}/wordpress/publish`, { method: 'POST', json: { items } }),
    onSuccess: () => { setChosen(null); refresh() },
  })
  const disconnect = useMutation({
    mutationFn: () => api(`/businesses/${business.id}/wordpress`, { method: 'DELETE' }),
    onSuccess: () => { setCode(null); setChosen(null); refresh() },
  })
  const c = q.data?.data.connector
  useEffect(() => { if (c?.status === 'connected') setCode(null) }, [c?.status])

  if (q.isLoading) return <Spinner />
  if (q.error) return <ErrorState error={q.error} retry={() => q.refetch()} />
  const d = q.data!.data
  const selected = chosen ?? (c?.published_items.length ? c.published_items : d.items.map((i) => i.key))
  const changed = !!c?.published_at && (d.outdated || [...selected].sort().join() !== [...c.published_items].sort().join())
  const pairErr = fieldErrors(pairing.error).website?.[0] ?? (pairing.error instanceof Error ? pairing.error.message : null)
  const toggle = (k: string) => setChosen(selected.includes(k) ? selected.filter((x) => x !== k) : [...selected, k])

  return (
    <div className="stack">
      <div className="card stack">
        <div className="row between">
          <h2 style={{ margin: 0 }}>WordPress website</h2>
          {c ? <Badge tone={c.status === 'connected' ? 'ok' : 'medium'}>{c.status === 'pending' ? 'Waiting for the plugin' : humanize(c.status)}</Badge> : <Badge>Not connected</Badge>}
        </div>
        <p className="small muted" style={{ margin: 0 }}>
          The free Findriva Connect plugin adds your approved business details to your WordPress homepage, so search engines and AI
          assistants read the same facts as your profile. Findriva never signs in to your website, and the plugin prints only what an
          owner or admin publishes here.
        </p>

        {!d.website && <p className="notice warn" style={{ margin: 0 }}>Add your website address in <Link to={`/businesses/${business.id}/profile`}>Business profile</Link> first.</p>}

        {d.website && c?.status !== 'connected' && (
          <ol className="stack small" style={{ margin: 0, paddingLeft: '1.25rem', gap: '0.5rem' }}>
            <li>Download the plugin: <a className="btn ghost sm" href={d.plugin.download_url}>findriva-connect.zip (v{d.plugin.version})</a></li>
            <li>In WordPress, go to Plugins → Add New → Upload Plugin, choose the file and activate it.</li>
            <li>Open Settings → Findriva Connect and paste this pairing code:
              {code
                ? <div className="row" style={{ marginTop: '0.5rem' }}><code className="pairing-code">{code.code}</code><span className="muted">Works once, until {when(code.expires_at)}.</span></div>
                : d.can_manage
                  ? <div style={{ marginTop: '0.5rem' }}><button className="btn primary sm" disabled={pairing.isPending} onClick={() => pairing.mutate()}>Create pairing code</button></div>
                  : <div className="muted">Ask an owner or admin to create a pairing code.</div>}
            </li>
          </ol>
        )}
        {pairErr && <p className="error-text">{pairErr}</p>}

        {c?.status === 'connected' && (
          <div className="small stack" style={{ gap: '0.25rem' }}>
            <div>Connected to <strong>{c.site_url}</strong>{c.wp_version ? <> · WordPress {c.wp_version}</> : null}{c.plugin_version ? <> · plugin {c.plugin_version}</> : null}</div>
            <div className="muted">Plugin last checked in {when(c.last_seen_at)}. It checks hourly, or press “Check now” in the plugin's settings.</div>
            {c.plugin_version && c.plugin_version !== d.plugin.version && <p className="notice warn" style={{ margin: 0 }}>A newer plugin (v{d.plugin.version}) is available. <a href={d.plugin.download_url}>Download it</a> and upload it in WordPress to update.</p>}
          </div>
        )}
      </div>

      {c?.status === 'connected' && (
        <div className="card stack">
          <div className="row between">
            <h3 style={{ margin: 0 }}>What to add to your homepage</h3>
            {c.published_at ? <Badge tone="ok">Published {when(c.published_at)}</Badge> : <Badge>Nothing published yet</Badge>}
          </div>
          {d.items.map((i) => (
            <label key={i.key} className="row small" style={{ gap: '0.5rem', flexWrap: 'nowrap' }}>
              <input type="checkbox" style={{ width: 'auto', flex: 'none' }} checked={selected.includes(i.key)} disabled={!d.can_manage} onChange={() => toggle(i.key)} />
              {i.label}
            </label>
          ))}
          <p className="small muted" style={{ margin: 0 }}>
            Built from your approved facts in Business profile. If an SEO plugin such as Yoast or Rank Math is active, the plugin leaves the
            description and share tags to it and adds only the structured data.
          </p>
          {selected.length > 0 && <pre className="code" aria-label="What the plugin will add">{previewText(d.preview, selected)}</pre>}
          {changed && <p className="notice" style={{ margin: 0 }}>Your website still shows the last published version. Publish to update it.</p>}
          {d.can_manage
            ? (
              <div className="row" style={{ flexWrap: 'wrap' }}>
                <button className="btn primary" disabled={publish.isPending} onClick={() => publish.mutate(selected)}>
                  {selected.length ? 'Approve and publish to my website' : 'Remove everything from my website'}
                </button>
                <button className="btn ghost sm danger" disabled={disconnect.isPending} onClick={() => disconnect.mutate()}>Disconnect website</button>
              </div>
            )
            : <p className="small muted" style={{ margin: 0 }}>Only an owner or admin can publish to the website.</p>}
          {publish.isSuccess && (
            <p className="notice" style={{ margin: 0 }}>
              Published. The plugin picks it up within the hour. Then run a new <Link to={`/businesses/${business.id}/audit`}>audit</Link> to confirm the change is live; Findriva only marks a fix verified after that check.
            </p>
          )}
        </div>
      )}
    </div>
  )
}
