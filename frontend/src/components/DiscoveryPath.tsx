import { useEffect, useId, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import type { LucideIcon } from 'lucide-react'

export type PathState = 'live' | 'partial' | 'off'
export interface PathNode { key: string; label: string; detail: string; icon: LucideIcon; state: PathState; to?: string }

interface Pt { x: number; y: number }

/**
 * The Discovery Path: a hub joined to its channels by dashed, curved lines. A line only carries a
 * moving signal when the channel behind it is really live, so the animation itself reports data.
 * Positions are computed in pixels from the measured width, so labels stay readable on phones.
 */
export function DiscoveryPath({ hub, nodes, compact = false }: { hub: { label: string; detail?: string; icon: LucideIcon }; nodes: PathNode[]; compact?: boolean }) {
  const ref = useRef<HTMLDivElement>(null)
  const [w, setW] = useState(0)
  const uid = useId().replace(/:/g, '')

  useEffect(() => {
    const el = ref.current
    if (!el) return
    const ro = new ResizeObserver(([e]) => setW(Math.round(e.contentRect.width)))
    ro.observe(el)
    return () => ro.disconnect()
  }, [])

  const wide = w >= 620
  const n = nodes.length
  // Hub on the left, channels on an arc to the right, so no line ever crosses another node's label.
  const gap = wide ? (compact ? 40 : 46) : 58
  const h = Math.max(compact ? 190 : 230, 40 + (n - 1) * gap + 40)
  const hubPt: Pt = wide ? { x: Math.min(150, w * 0.16), y: h / 2 } : { x: 48, y: h / 2 }
  const pts: Pt[] = nodes.map((_, i) => {
    const t = n === 1 ? 0.5 : i / (n - 1)
    const bow = 1 - Math.abs(t - 0.5) * 2 // 1 in the middle, 0 at the ends
    const y = 40 + i * gap
    return wide
      ? { x: w - 200 - bow * w * 0.1, y }
      : { x: Math.max(130, w - 210) - bow * 22, y }
  })

  // Quadratic curves bowed away from the straight line, so every route reads as a parabola.
  const curve = (a: Pt, b: Pt) => {
    const cx = a.x + (b.x - a.x) * 0.5
    const cy = b.y + (b.y - a.y) * 0.18
    return `M${a.x},${a.y} Q${cx},${cy} ${b.x},${b.y}`
  }

  return (
    <div ref={ref} className={`dp ${wide ? 'dp-wide' : 'dp-narrow'}`} style={{ height: w ? h : 230 }}>
      {w > 0 && (
        <>
          <svg className="dp-svg" width={w} height={h} viewBox={`0 0 ${w} ${h}`} aria-hidden>
            <defs>
              <linearGradient id={`g${uid}`} x1="0" x2="1" y1="0" y2="0">
                <stop offset="0" stopColor="#2878F7" />
                <stop offset="1" stopColor="#43D6C3" />
              </linearGradient>
              <filter id={`f${uid}`} x="-20%" y="-20%" width="140%" height="140%">
                <feGaussianBlur stdDeviation="3" result="b" />
                <feMerge><feMergeNode in="b" /><feMergeNode in="SourceGraphic" /></feMerge>
              </filter>
            </defs>
            {nodes.map((node, i) => {
              const d = curve(hubPt, pts[i])
              const id = `p${uid}${i}`
              return (
                <g key={node.key}>
                  <path id={id} d={d} className="dp-base" />
                  {node.state !== 'off' && (
                    <path d={d} className={`dp-live ${node.state}`} stroke={`url(#g${uid})`} filter={`url(#f${uid})`} style={{ animationDelay: `${-i * 0.7}s` }} />
                  )}
                  {node.state === 'live' && (
                    <circle r="3.5" className="dp-dot" filter={`url(#f${uid})`}>
                      <animateMotion dur={`${3.2 + (i % 3) * 0.6}s`} begin={`${-i * 0.9}s`} repeatCount="indefinite" calcMode="spline" keyTimes="0;1" keySplines="0.4 0 0.2 1">
                        <mpath href={`#${id}`} />
                      </animateMotion>
                    </circle>
                  )}
                </g>
              )
            })}
          </svg>
          <div className="dp-hub" style={{ left: hubPt.x, top: hubPt.y }}>
            <span className="dp-hub-icon"><hub.icon size={22} strokeWidth={1.8} /></span>
            <span className="dp-hub-label">{hub.label}</span>
            {hub.detail && <span className="dp-detail">{hub.detail}</span>}
          </div>
          {nodes.map((node, i) => {
            const inner = (
              <>
                <span className="dp-node-icon"><node.icon size={16} strokeWidth={1.9} /></span>
                <span className="dp-node-text"><span className="dp-node-label">{node.label}</span><span className="dp-detail">{node.detail}</span></span>
              </>
            )
            const style = { left: pts[i].x, top: pts[i].y }
            const cls = `dp-node ${node.state}`
            return node.to
              ? <Link key={node.key} to={node.to} className={cls} style={style}>{inner}</Link>
              : <div key={node.key} className={cls} style={style}>{inner}</div>
          })}
        </>
      )}
    </div>
  )
}
