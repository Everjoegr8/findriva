import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { CornerDownLeft, Search, type LucideIcon } from 'lucide-react'

export interface Command { id: string; label: string; group: string; to: string; icon: LucideIcon; keywords?: string }

/** Rule-based finder: matches every word typed against labels and keywords. No AI involved. */
export function rank(commands: Command[], query: string) {
  const words = query.toLowerCase().trim().split(/\s+/).filter(Boolean)
  if (!words.length) return commands
  return commands
    .map((c) => {
      const label = c.label.toLowerCase()
      const hay = `${label} ${c.group.toLowerCase()} ${c.keywords ?? ''}`.toLowerCase()
      if (!words.every((w) => hay.includes(w))) return null
      const score = (label.startsWith(words[0]) ? 0 : label.includes(words[0]) ? 1 : 2)
      return { c, score }
    })
    .filter((x): x is { c: Command; score: number } => x !== null)
    .sort((a, b) => a.score - b.score)
    .map((x) => x.c)
}

export function CommandPalette({ open, onClose, commands, placeholder = 'Search pages and businesses' }: { open: boolean; onClose: () => void; commands: Command[]; placeholder?: string }) {
  const [q, setQ] = useState('')
  const [i, setI] = useState(0)
  const input = useRef<HTMLInputElement>(null)
  const navigate = useNavigate()
  const results = useMemo(() => rank(commands, q).slice(0, 12), [commands, q])

  useEffect(() => { if (open) { setQ(''); setI(0) } }, [open])
  useEffect(() => { setI(0) }, [q])
  if (!open) return null

  const go = (c: Command | undefined) => { if (c) { navigate(c.to); onClose() } }

  return (
    <div className="cmdk-scrim" onMouseDown={onClose}>
      <div className="cmdk" role="dialog" aria-modal="true" aria-label="Search" onMouseDown={(e) => e.stopPropagation()}>
        <div className="cmdk-input">
          <Search size={18} aria-hidden />
          <input
            ref={input} autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder={placeholder} aria-label={placeholder}
            role="combobox" aria-expanded aria-controls="cmdk-list" aria-activedescendant={results[i] ? `cmdk-${results[i].id}` : undefined}
            onKeyDown={(e) => {
              if (e.key === 'ArrowDown') { e.preventDefault(); setI((x) => Math.min(x + 1, results.length - 1)) }
              else if (e.key === 'ArrowUp') { e.preventDefault(); setI((x) => Math.max(x - 1, 0)) }
              else if (e.key === 'Enter') { e.preventDefault(); go(results[i]) }
              else if (e.key === 'Escape') onClose()
            }}
          />
          <kbd>Esc</kbd>
        </div>
        <ul id="cmdk-list" role="listbox" className="cmdk-list">
          {results.length === 0 && <li className="cmdk-empty">Nothing matches “{q}”.</li>}
          {results.map((c, n) => (
            <li
              key={c.id} id={`cmdk-${c.id}`} role="option" aria-selected={n === i} className={n === i ? 'on' : ''}
              onMouseEnter={() => setI(n)} onClick={() => go(c)}
            >
              <c.icon size={16} aria-hidden />
              <span className="cmdk-label">{c.label}</span>
              <span className="cmdk-group">{c.group}</span>
              {n === i && <CornerDownLeft size={14} aria-hidden className="cmdk-enter" />}
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}

/** Opens the palette on Ctrl+K / Cmd+K or “/”. */
export function useCommandShortcut(set: (v: boolean) => void) {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const t = e.target as HTMLElement
      const typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)
      if ((e.key === 'k' && (e.metaKey || e.ctrlKey)) || (e.key === '/' && !typing)) { e.preventDefault(); set(true) }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [set])
}
