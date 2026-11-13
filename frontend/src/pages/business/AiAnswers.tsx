import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type Business } from '../../api'
import { useAuth, useRole } from '../../auth'
import { Badge, Empty, ErrorState, Field, Spinner, fieldErrors, humanize } from '../../ui'

interface Source { url: string; title: string; cited: boolean; domain: string; kind: 'own' | 'platform' | 'other' }
interface Row {
  id: number; question: string; active: boolean; within_allowance: boolean
  latest: { checked_at: string; engine: string; model: string; location: string | null; named: boolean; own_site_cited: boolean; excerpt: string | null; answer: string; sources: Source[] } | null
  history: { date: string; named: boolean }[]
}
interface AiResponse {
  data: Row[]
  summary: { questions: number; asked: number; named: number; own_site_cited: number; last_checked_at: string | null }
  often_cited: { domain: string; kind: string; answers: number }[]
  engine: { name: string; model: string; configured: boolean }
  location: { city: string | null; country_name: string }
  allowance: number; cap_usd_per_day: number
  last_run: { status: string; message: string; finished_at: string } | null
}

/** Shows **bold** from the assistant's Markdown as bold; everything else stays plain text. */
function Rich({ text }: { text: string }) {
  return <>{text.split(/\*\*(.+?)\*\*/g).map((part, i) => (i % 2 ? <strong key={i}>{part}</strong> : part))}</>
}

export default function AiAnswersTab({ business }: { business: Business }) {
  const { workspaceId } = useAuth()
  const { canEdit } = useRole()
  const qc = useQueryClient()
  const [waitingSince, setWaitingSince] = useState<number | null>(null)
  const [question, setQuestion] = useState('')
  const [open, setOpen] = useState<number | null>(null)
  const key = ['ai-answers', workspaceId, business.id]
  const base = `/businesses/${business.id}`
  const q = useQuery({ queryKey: key, queryFn: () => api<AiResponse>(`${base}/ai-answers`), refetchInterval: waitingSince === null ? false : 4000 })
  const invalidate = () => void qc.invalidateQueries({ queryKey: key })
  const check = useMutation({ mutationFn: () => api(`${base}/ai-answers/check`, { method: 'POST' }), onSuccess: () => { setWaitingSince(Date.now()); invalidate() } })
  const suggest = useMutation({ mutationFn: () => api<{ data: { added: number } }>(`${base}/ai-questions/suggest`, { method: 'POST' }), onSuccess: invalidate })
  const add = useMutation({ mutationFn: () => api(`${base}/ai-questions`, { method: 'POST', json: { question } }), onSuccess: () => { setQuestion(''); invalidate() } })
  const toggle = useMutation({ mutationFn: ({ id, active }: { id: number; active: boolean }) => api(`${base}/ai-questions/${id}`, { method: 'PATCH', json: { active } }), onSuccess: invalidate })
  const remove = useMutation({ mutationFn: (id: number) => api(`${base}/ai-questions/${id}`, { method: 'DELETE' }), onSuccess: invalidate })

  const finishedAt = q.data?.last_run?.finished_at
  useEffect(() => {
    if (waitingSince === null) return
    if ((finishedAt && Date.parse(finishedAt) >= waitingSince - 2000) || Date.now() - waitingSince > 300_000) setWaitingSince(null)
  }, [finishedAt, waitingSince])

  if (q.isLoading) return <Spinner />
  if (q.error) return <ErrorState error={q.error} retry={() => void q.refetch()} />
  const d = q.data!
  const s = d.summary
  const checking = waitingSince !== null || check.isPending
  const canCheck = d.engine.configured && d.allowance > 0
  const place = [d.location.city, d.location.country_name].filter(Boolean).join(', ')

  return (
    <div className="stack">
      <p className="muted">
        More people now ask AI assistants for recommendations. We ask one the questions your customers ask, from {place || 'your area'}, with live web search,
        and check whether it names you and which websites it relies on. We never put your name in the question. Each answer is one snapshot from one
        assistant; others may answer differently.
      </p>

      <div className="card row between">
        <div className="small">
          {!d.engine.configured
            ? <>AI answers are <strong>not checked yet</strong>. They appear once an AI assistant is connected to Findriva.</>
            : d.allowance > 0
              ? <>Asked to <strong>{humanize(d.engine.name)}</strong> with web search, weekly. Your plan covers {d.allowance} questions.</>
              : <>Your plan does not include AI answer checks. <Link to="/billing">Compare plans</Link>.</>}
        </div>
        {canEdit && s.questions > 0 && canCheck && (
          <button className="btn primary" disabled={checking} onClick={() => check.mutate()}>{checking ? 'Asking…' : 'Ask now'}</button>
        )}
      </div>
      {d.last_run && !checking && <p className={`notice ${['checked', 'up_to_date'].includes(d.last_run.status) ? '' : 'warn'}`}>{d.last_run.message}</p>}

      {s.asked > 0 && (
        <div className="grid" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: '0.75rem' }}>
          <div className="card stat"><div className="label">Questions asked</div><div className="value">{s.asked}</div></div>
          <div className="card stat"><div className="label">Answers that name you</div><div className="value">{s.named}</div></div>
          <div className="card stat"><div className="label">Answers citing your site or profile</div><div className="value">{s.own_site_cited}</div></div>
        </div>
      )}

      {d.data.length === 0 ? (
        <Empty title="No questions yet">
          Create questions from the keywords you track, or write the question a customer would type.
        </Empty>
      ) : (
        <div className="stack">
          {d.data.map((r) => (
            <div key={r.id} className="card stack" style={{ gap: '0.5rem', opacity: r.active ? 1 : 0.6 }}>
              <div className="row between" style={{ alignItems: 'flex-start' }}>
                <strong style={{ minWidth: 0, overflowWrap: 'anywhere' }}>“{r.question}”</strong>
                {r.latest
                  ? <Badge tone={r.latest.named ? 'ok' : 'high'}>{r.latest.named ? 'Names you' : 'Does not name you'}</Badge>
                  : <Badge>{r.active ? 'Not asked yet' : 'Paused'}</Badge>}
              </div>
              {r.active && !r.within_allowance && d.allowance > 0 && <div className="small muted">Beyond your plan's allowance</div>}
              {r.latest && (
                <>
                  {r.latest.excerpt && <p className="small" style={{ margin: 0, whiteSpace: 'pre-wrap' }}><Rich text={r.latest.excerpt} /></p>}
                  <div className="small muted">
                    {new Date(r.latest.checked_at).toLocaleDateString()} · {humanize(r.latest.engine)}{r.latest.location ? ` · asked from ${r.latest.location}` : ''}
                    {r.latest.own_site_cited && ' · cites your site or profile'}
                    {r.history.length > 1 && ` · named in ${r.history.filter((h) => h.named).length} of the last ${r.history.length} checks`}
                  </div>
                  <button className="btn ghost sm" style={{ alignSelf: 'flex-start' }} aria-expanded={open === r.id} onClick={() => setOpen(open === r.id ? null : r.id)}>
                    {open === r.id ? 'Hide answer' : 'See the full answer and sources'}
                  </button>
                  {open === r.id && (
                    <div className="stack" style={{ gap: '0.5rem', background: 'var(--surface-2)', padding: '0.75rem', borderRadius: 8 }}>
                      <div className="small" style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}><Rich text={r.latest.answer} /></div>
                      {r.latest.sources.filter((x) => x.cited).length > 0 && (
                        <div className="small">
                          <div className="muted">Sources it cited</div>
                          <ul style={{ margin: 0, paddingLeft: '1.1rem' }}>
                            {r.latest.sources.filter((x) => x.cited).map((x) => (
                              <li key={x.url} style={{ overflowWrap: 'anywhere' }}>
                                <a href={x.url} target="_blank" rel="noreferrer noopener">{x.title || x.domain}</a> <span className="muted">· {x.domain}</span>
                                {x.kind === 'own' && <> <Badge tone="ok">You</Badge></>}
                                {x.kind === 'platform' && <> <Badge>Directory or platform</Badge></>}
                              </li>
                            ))}
                          </ul>
                        </div>
                      )}
                    </div>
                  )}
                </>
              )}
              {canEdit && (
                <div className="row">
                  <button className="btn ghost sm" onClick={() => toggle.mutate({ id: r.id, active: !r.active })}>{r.active ? 'Pause' : 'Resume'}</button>
                  <button className="btn ghost sm danger" onClick={() => remove.mutate(r.id)}>Remove</button>
                </div>
              )}
            </div>
          ))}
        </div>
      )}

      {canEdit && (
        <div className="card stack">
          <form className="row" style={{ alignItems: 'flex-end', flexWrap: 'wrap' }} onSubmit={(e) => { e.preventDefault(); if (question.trim()) add.mutate() }}>
            <div style={{ flex: '1 1 260px' }}>
              <Field label="Add a question customers ask" hint="Without your business name, e.g. “Who does bridal makeup in Lekki?”" error={fieldErrors(add.error).question}>
                <input value={question} onChange={(e) => setQuestion(e.target.value)} maxLength={300} placeholder="Who does bridal makeup in Lekki?" />
              </Field>
            </div>
            <button className="btn" disabled={add.isPending || question.trim().length < 8}>Add</button>
          </form>
          <div className="row" style={{ flexWrap: 'wrap' }}>
            <button className="btn ghost sm" disabled={suggest.isPending} onClick={() => suggest.mutate()}>Create questions from my tracked keywords</button>
            {suggest.data && <span className="small muted">{suggest.data.data.added ? `Added ${suggest.data.data.added}.` : 'No new questions. Track more keywords first.'}</span>}
          </div>
        </div>
      )}

      {d.often_cited.length > 0 && (
        <section className="stack">
          <h3 style={{ margin: 0 }}>Websites the assistant relies on</h3>
          <p className="small muted" style={{ margin: 0 }}>Sites it cited across your latest answers. Being listed accurately on the directories and platforms here makes it more likely to find you.</p>
          <div className="card table-wrap">
            <table>
              <thead><tr><th>Site</th><th>Type</th><th>Answers citing it</th></tr></thead>
              <tbody>
                {d.often_cited.map((c) => (
                  <tr key={c.domain}><td style={{ overflowWrap: 'anywhere' }}>{c.domain}</td><td className="small">{c.kind === 'platform' ? 'Directory or platform' : 'Business or publisher'}</td><td>{c.answers}</td></tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </div>
  )
}
