import { useEffect, useRef, useState } from 'react'
import { Download, FileSpreadsheet } from 'lucide-react'
import { download } from '../api'
import { moduleOn, usePlatform } from '../platform'

const EXPORTS: { type: string; label: string; module?: string }[] = [
  { type: 'actions', label: 'Action Centre tasks' },
  { type: 'issues', label: 'Audit issues' },
  { type: 'profiles', label: 'Profiles & listings' },
  { type: 'search', label: 'Search clicks and impressions' },
  { type: 'keywords', label: 'Keywords', module: 'keywords' },
  { type: 'rankings', label: 'Ranking history', module: 'rankings' },
  { type: 'reviews', label: 'Google reviews', module: 'google_profile' },
  { type: 'ai-answers', label: 'AI answer checks', module: 'ai_answers' },
]

/** CSV downloads of the business's own records (open in Excel or Google Sheets). */
export function ExportMenu({ businessId }: { businessId: number }) {
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const ref = useRef<HTMLDivElement>(null)
  const platform = usePlatform()
  useEffect(() => {
    if (!open) return
    const close = (e: MouseEvent) => { if (!ref.current?.contains(e.target as Node)) setOpen(false) }
    const key = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false) }
    document.addEventListener('mousedown', close); window.addEventListener('keydown', key)
    return () => { document.removeEventListener('mousedown', close); window.removeEventListener('keydown', key) }
  }, [open])

  const get = async (type: string) => {
    setBusy(type); setError(null)
    try { await download(`/businesses/${businessId}/export/${type}`, `${type}.csv`); setOpen(false) }
    catch (e) { setError(e instanceof Error ? e.message : 'Download failed.') }
    finally { setBusy(null) }
  }

  return (
    <div className="switcher" ref={ref}>
      <button className="btn sm" aria-haspopup="menu" aria-expanded={open} onClick={() => setOpen((v) => !v)}><Download size={15} aria-hidden /> Export</button>
      {open && (
        <div className="switcher-menu menu-right" role="menu">
          <div className="menu-head small muted">Download as CSV (opens in Excel or Google Sheets)</div>
          {EXPORTS.filter((x) => moduleOn(platform.data, x.module)).map((x) => (
            <button key={x.type} role="menuitem" className="menu-item" disabled={busy !== null} onClick={() => void get(x.type)}>
              <FileSpreadsheet size={15} aria-hidden /><span>{busy === x.type ? 'Preparing…' : x.label}</span>
            </button>
          ))}
          {error && <div className="error-text" style={{ padding: '6px 10px' }}>{error}</div>}
        </div>
      )}
    </div>
  )
}
