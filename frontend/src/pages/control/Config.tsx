import { useEffect, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, CircleDashed, KeyRound } from 'lucide-react'
import { api } from '../../api'
import { Badge, ErrorState, Field, PageTitle, Spinner, fieldErrors, humanize } from '../../ui'

interface Plan { label: string; price_kobo: number | null; limits: Record<string, number>; defaults: Record<string, number | string | null> }
interface Provider { key: string; name: string; powers: string; configured: boolean; env: string[]; facts?: Record<string, string>; connections?: Record<string, Record<string, number>> }
interface Model { id: string; label: string; input: number; output: number }
interface PlatformData {
  plans: Record<string, Plan>; limit_labels: Record<string, string>; paystack_plan_codes: Record<string, boolean>
  modules: { key: string; label: string; description: string; enabled: boolean }[]
  ai: { answers_enabled: boolean; answers_model: string; content_engine: 'template' | 'claude'; content_model: string; key_configured: boolean; models: Model[]; answer_spend_30d_usd: number }
  general: { signups_open: boolean; announcement: string | null }
  providers: Provider[]
}

function usePlatformAdmin() {
  return useQuery({ queryKey: ['admin', 'platform'], queryFn: () => api<{ data: PlatformData }>('/admin/platform').then((r) => r.data) })
}

function useSave<T>(path: string, onDone?: () => void) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (json: T) => api(path, { method: 'PUT', json }),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['admin', 'platform'] }); void qc.invalidateQueries({ queryKey: ['platform'] }); onDone?.() },
  })
}

export function Toggle({ checked, onChange, label, disabled }: { checked: boolean; onChange: (v: boolean) => void; label: string; disabled?: boolean }) {
  return (
    <button type="button" role="switch" aria-checked={checked} aria-label={label} disabled={disabled} className={`switch ${checked ? 'on' : ''}`} onClick={() => onChange(!checked)}>
      <span />
    </button>
  )
}

function Loading({ q }: { q: { isLoading: boolean; error: unknown; refetch: () => unknown } }) {
  if (q.isLoading) return <Spinner />
  if (q.error) return <ErrorState error={q.error} retry={() => void q.refetch()} />
  return null
}

const naira = (kobo: number) => '₦' + Math.round(kobo / 100).toLocaleString()

// ---------- Plans & limits ----------
function PlanCard({ id, plan, labels, hasPaystackCode }: { id: string; plan: Plan; labels: Record<string, string>; hasPaystackCode: boolean }) {
  const [label, setLabel] = useState(plan.label)
  const [price, setPrice] = useState(plan.price_kobo !== null ? String(plan.price_kobo / 100) : '')
  const [limits, setLimits] = useState<Record<string, string>>(() => Object.fromEntries(Object.entries(plan.limits).map(([k, v]) => [k, String(v)])))
  const [saved, setSaved] = useState(false)
  const save = useSave<unknown>(`/admin/platform/plans/${id}`, () => setSaved(true))
  useEffect(() => { if (saved) { const t = setTimeout(() => setSaved(false), 2500); return () => clearTimeout(t) } }, [saved])
  const errs = fieldErrors(save.error)
  const changed = label !== plan.label || (plan.price_kobo !== null && Number(price) * 100 !== plan.price_kobo) || Object.entries(plan.limits).some(([k, v]) => Number(limits[k]) !== v)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate({
      label, limits: Object.fromEntries(Object.entries(limits).map(([k, v]) => [k, Number(v)])),
      ...(plan.price_kobo !== null ? { price_kobo: Math.round(Number(price) * 100) } : {}),
    })
  }
  return (
    <form className="card stack plan-card" onSubmit={submit}>
      <div className="row between">
        <div className="row"><h2 style={{ margin: 0 }}>{plan.label}</h2><Badge>{id}</Badge></div>
        <span className="small muted">{plan.price_kobo === null ? 'Free' : `${naira(plan.price_kobo)} a month`}</span>
      </div>
      <div className="plan-grid">
        <Field label="Name" error={errs.label}><input value={label} onChange={(e) => setLabel(e.target.value)} maxLength={40} /></Field>
        {plan.price_kobo !== null && (
          <Field label="Monthly price (₦)" hint={`Default ${naira(Number(plan.defaults.price_kobo))}`} error={errs.price_kobo}>
            <input type="number" inputMode="numeric" min={100} step={1} value={price} onChange={(e) => setPrice(e.target.value)} />
          </Field>
        )}
        {Object.keys(plan.limits).map((k) => (
          <Field key={k} label={labels[k] ?? humanize(k)} hint={`Default ${plan.defaults[k]}`} error={errs[`limits.${k}`]}>
            <input type="number" min={0} step={k.includes('_usd_') ? 0.05 : 1} value={limits[k]} onChange={(e) => setLimits({ ...limits, [k]: e.target.value })} />
          </Field>
        ))}
      </div>
      {plan.price_kobo !== null && hasPaystackCode && (
        <p className="notice warn small" style={{ margin: 0 }}>This plan has a Paystack plan code, so renewals are charged at the amount set in Paystack. Change it there too.</p>
      )}
      <div className="row">
        <button className="btn primary" disabled={!changed || save.isPending}>{save.isPending ? 'Saving…' : 'Save plan'}</button>
        {saved && <span className="small" style={{ color: 'var(--ok)' }}>Saved. New limits apply right away; a new price applies to the next checkout.</span>}
        {save.error && !Object.keys(errs).length ? <span className="error-text">{(save.error as Error).message}</span> : null}
      </div>
    </form>
  )
}

export function PlansPage() {
  const q = usePlatformAdmin()
  return (
    <div className="stack">
      <PageTitle title="Plans & limits" sub="What each plan includes and costs. Plans buy coverage (more businesses, pages and checks), never rankings. Every change is recorded in the activity log." />
      <Loading q={q} />
      {q.data && Object.entries(q.data.plans).map(([id, p]) => (
        <PlanCard key={id} id={id} plan={p} labels={q.data.limit_labels} hasPaystackCode={!!q.data.paystack_plan_codes[id]} />
      ))}
    </div>
  )
}

// ---------- Modules ----------
export function ModulesPage() {
  const q = usePlatformAdmin()
  const save = useSave<Record<string, boolean>>('/admin/platform/modules')
  return (
    <div className="stack">
      <PageTitle title="Modules" sub="Switch a feature off for every customer at once, for example while a provider has an outage. Customers see it as paused, and scheduled runs for it stand down. Nothing is deleted." />
      <Loading q={q} />
      {q.data && (
        <div className="card toggle-list">
          {q.data.modules.map((m) => (
            <div key={m.key} className="toggle-row">
              <div><strong>{m.label}</strong><span className="small muted">{m.description}</span></div>
              <Badge tone={m.enabled ? 'ok' : 'high'}>{m.enabled ? 'On' : 'Paused'}</Badge>
              <Toggle checked={m.enabled} label={`${m.label} on`} disabled={save.isPending} onChange={(v) => save.mutate({ [m.key]: v })} />
            </div>
          ))}
        </div>
      )}
      {save.error ? <ErrorState error={save.error} /> : null}
    </div>
  )
}

// ---------- Providers ----------
export function ProvidersPage() {
  const q = usePlatformAdmin()
  return (
    <div className="stack">
      <PageTitle title="Providers" sub="The outside services Findriva relies on. Keys live only in the server environment: this page shows whether each one is set, never the key itself." />
      <Loading q={q} />
      {q.data && (
        <div className="provider-grid">
          {q.data.providers.map((p) => (
            <div key={p.key} className="card stack provider-card">
              <div className="row between">
                <h2 style={{ margin: 0 }}>{p.name}</h2>
                {p.configured
                  ? <span className="provider-state ok"><CheckCircle2 size={15} aria-hidden /> Set up</span>
                  : <span className="provider-state off"><CircleDashed size={15} aria-hidden /> Not set up</span>}
              </div>
              <p className="small muted" style={{ margin: 0 }}>{p.powers}</p>
              {p.connections && Object.entries(p.connections).map(([name, counts]) => (
                <div key={name} className="row between small"><span>{name}</span>
                  <span className="row" style={{ gap: '0.35rem' }}>{Object.keys(counts).length ? Object.entries(counts).map(([s, n]) => <Badge key={s} tone={s === 'connected' ? 'ok' : s === 'error' ? 'critical' : ''}>{n} {humanize(s).toLowerCase()}</Badge>) : <span className="muted">No connections yet</span>}</span>
                </div>
              ))}
              {p.facts && Object.entries(p.facts).map(([k, v]) => <div key={k} className="row between small"><span>{k}</span><span className="muted">{v}</span></div>)}
              {p.env.length > 0 && (
                <div className="env-list small"><KeyRound size={13} aria-hidden /> {p.env.map((e) => <code key={e}>{e}</code>)}</div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

// ---------- AI models ----------
export function AiPage() {
  const q = usePlatformAdmin()
  const [form, setForm] = useState<PlatformData['ai'] | null>(null)
  const [saved, setSaved] = useState(false)
  const save = useSave<unknown>('/admin/platform/ai', () => setSaved(true))
  useEffect(() => { if (q.data) setForm(q.data.ai) }, [q.data])
  const f = form
  return (
    <div className="stack">
      <PageTitle title="AI models" sub="AI is optional in Findriva. Every feature has a version that works without it, and nothing AI writes is published until the customer approves it." />
      <Loading q={q} />
      {q.data && f && (
        <form className="stack" onSubmit={(e) => { e.preventDefault(); setSaved(false); save.mutate({ answers_enabled: f.answers_enabled, answers_model: f.answers_model, content_engine: f.content_engine, content_model: f.content_model }) }}>
          {!q.data.ai.key_configured && <p className="notice warn" style={{ margin: 0 }}>No Anthropic key is set on the server (ANTHROPIC_API_KEY), so every AI feature runs in its no-AI mode whatever you choose here.</p>}
          <div className="card stack">
            <div className="toggle-row" style={{ padding: 0, border: 0 }}>
              <div><strong>AI answer checks</strong><span className="small muted">Asks an AI assistant with web search the questions customers track, and records whether the business is mentioned. {q.data.ai.answer_spend_30d_usd ? `$${q.data.ai.answer_spend_30d_usd.toFixed(2)} spent in 30 days.` : 'Nothing spent in 30 days.'}</span></div>
              <Toggle checked={f.answers_enabled} label="AI answer checks on" onChange={(v) => setForm({ ...f, answers_enabled: v })} />
            </div>
            <Field label="Model for answer checks">
              <select value={f.answers_model} onChange={(e) => setForm({ ...f, answers_model: e.target.value })} disabled={!f.answers_enabled}>
                {q.data.ai.models.map((m) => <option key={m.id} value={m.id}>{m.label}: ${m.input} in / ${m.output} out per million tokens</option>)}
              </select>
            </Field>
          </div>
          <div className="card stack">
            <div><strong>Content Studio drafts</strong><div className="small muted">Facts-only drafts are written from the business's approved details with no AI. AI drafting uses the same facts and is reviewed before anything is published.</div></div>
            <div className="chips" role="radiogroup" aria-label="Drafting engine">
              <button type="button" role="radio" aria-pressed={f.content_engine === 'template'} aria-checked={f.content_engine === 'template'} onClick={() => setForm({ ...f, content_engine: 'template' })}>Facts only (no AI)</button>
              <button type="button" role="radio" aria-pressed={f.content_engine === 'claude'} aria-checked={f.content_engine === 'claude'} onClick={() => setForm({ ...f, content_engine: 'claude' })}>AI drafting</button>
            </div>
            <Field label="Model for AI drafting">
              <select value={f.content_model} onChange={(e) => setForm({ ...f, content_model: e.target.value })} disabled={f.content_engine !== 'claude'}>
                {q.data.ai.models.map((m) => <option key={m.id} value={m.id}>{m.label}</option>)}
              </select>
            </Field>
          </div>
          <div className="row">
            <button className="btn primary" disabled={save.isPending}>{save.isPending ? 'Saving…' : 'Save AI settings'}</button>
            {saved && <span className="small" style={{ color: 'var(--ok)' }}>Saved. Applies to the next check or draft.</span>}
            {save.error ? <span className="error-text">{(save.error as Error).message}</span> : null}
          </div>
        </form>
      )}
    </div>
  )
}

// ---------- Settings ----------
export function SettingsPage() {
  const q = usePlatformAdmin()
  const [open, setOpen] = useState(true)
  const [text, setText] = useState('')
  const [saved, setSaved] = useState(false)
  const save = useSave<unknown>('/admin/platform/general', () => setSaved(true))
  useEffect(() => { if (q.data) { setOpen(q.data.general.signups_open); setText(q.data.general.announcement ?? '') } }, [q.data])
  const errs = fieldErrors(save.error)
  return (
    <div className="stack">
      <PageTitle title="Settings" sub="Switches that affect everyone using Findriva." />
      <Loading q={q} />
      {q.data && (
        <form className="card stack" onSubmit={(e) => { e.preventDefault(); setSaved(false); save.mutate({ signups_open: open, announcement: text.trim() || null }) }}>
          <div className="toggle-row" style={{ padding: 0, border: 0 }}>
            <div><strong>New sign-ups</strong><span className="small muted">When paused, the sign-up page says so and new accounts are refused. People who already have an account can still sign in.</span></div>
            <Toggle checked={open} label="New sign-ups open" onChange={setOpen} />
          </div>
          <Field label="Announcement for customers" hint="Shown at the top of every customer's workspace, for example planned maintenance. Leave empty for none." error={errs.announcement}>
            <textarea rows={3} maxLength={280} value={text} onChange={(e) => setText(e.target.value)} />
          </Field>
          <div className="row">
            <button className="btn primary" disabled={save.isPending}>{save.isPending ? 'Saving…' : 'Save settings'}</button>
            {saved && <span className="small" style={{ color: 'var(--ok)' }}>Saved.</span>}
          </div>
        </form>
      )}
    </div>
  )
}
