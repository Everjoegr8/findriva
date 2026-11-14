import { useEffect, useId, useState, type InputHTMLAttributes } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../api'
import { useAuth } from '../auth'

type Field = 'categories' | 'cities' | 'services'

/** Rule-based suggestions from /suggest (no AI). Typing anything else is always allowed. */
export function useSuggestions(field: Field, q: string, category?: string, enabled = true) {
  const { workspaceId } = useAuth()
  const [debounced, setDebounced] = useState(q)
  useEffect(() => { const t = setTimeout(() => setDebounced(q), 150); return () => clearTimeout(t) }, [q])
  return useQuery({
    queryKey: ['suggest', workspaceId, field, debounced.trim().toLowerCase(), category ?? ''],
    queryFn: () => api<{ data: string[] }>(`/suggest/${field}?q=${encodeURIComponent(debounced.trim())}${category ? `&category=${encodeURIComponent(category)}` : ''}`).then((r) => r.data),
    enabled: enabled && !!workspaceId,
    staleTime: 5 * 60_000,
    placeholderData: (prev) => prev,
  })
}

export function Autocomplete({ field, value, onChange, category, ...rest }: {
  field: Field; value: string; onChange: (v: string) => void; category?: string
} & Omit<InputHTMLAttributes<HTMLInputElement>, 'value' | 'onChange'>) {
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(-1)
  const id = useId()
  const s = useSuggestions(field, value, category, open)
  const items = (s.data ?? []).filter((x) => x.toLowerCase() !== value.trim().toLowerCase())
  const show = open && items.length > 0

  const pick = (v: string) => { onChange(v); setOpen(false); setActive(-1) }

  return (
    <div className="ac">
      <input
        {...rest}
        value={value}
        role="combobox" aria-autocomplete="list" aria-expanded={show} aria-controls={`${id}-list`}
        aria-activedescendant={show && active >= 0 ? `${id}-${active}` : undefined}
        autoComplete="off"
        onChange={(e) => { onChange(e.target.value); setOpen(true); setActive(-1) }}
        onFocus={() => setOpen(true)}
        onBlur={() => setTimeout(() => setOpen(false), 120)}
        onKeyDown={(e) => {
          if (!show) return
          if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(a + 1, items.length - 1)) }
          else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(a - 1, 0)) }
          else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); pick(items[active]) }
          else if (e.key === 'Escape') setOpen(false)
        }}
      />
      {show && (
        <ul id={`${id}-list`} role="listbox" className="ac-list">
          {items.map((it, i) => (
            <li key={it} id={`${id}-${i}`} role="option" aria-selected={i === active} className={i === active ? 'on' : ''}
              onMouseDown={(e) => { e.preventDefault(); pick(it) }} onMouseEnter={() => setActive(i)}>{it}</li>
          ))}
        </ul>
      )}
    </div>
  )
}

/** Tappable service ideas for a category; tapping adds one. */
export function ServiceIdeas({ category, existing, onAdd }: { category: string; existing: string[]; onAdd: (s: string) => void }) {
  const s = useSuggestions('services', '', category, !!category.trim())
  const have = new Set(existing.map((x) => x.trim().toLowerCase()))
  const ideas = (s.data ?? []).filter((x) => !have.has(x.toLowerCase()))
  if (!category.trim() || ideas.length === 0) return null
  return (
    <div className="stack" style={{ gap: '0.35rem' }}>
      <span className="small muted">Common for {category.trim()}, tap to add:</span>
      <div className="chips">{ideas.map((x) => <button type="button" key={x} onClick={() => onAdd(x)}>+ {x}</button>)}</div>
    </div>
  )
}
