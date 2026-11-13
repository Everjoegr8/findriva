import { useEffect, useRef } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../api'
import { useAuth } from '../auth'
import { Badge, ErrorState, Spinner, fieldErrors, humanize } from '../ui'

interface Plan { key: string; label: string; price: number; businesses: number; locations_per_business: number; pages_per_crawl: number; crawls_per_month: number; content_generations_per_day: number; keyword_spend_usd_per_day: number }
interface BillingData {
  plan: string; billing_status: string; current_period_end: string | null; cancel_at_period_end: boolean; currency: string
  plans: Plan[]; online_payment: boolean; can_manage: boolean; history: { reference: string; plan: string; amount: number; currency: string; paid_at: string }[]
}

const naira = (kobo: number) => (kobo === 0 ? 'Free' : `₦${(kobo / 100).toLocaleString()}`)

export default function BillingPage() {
  const { workspaceId, refresh } = useAuth()
  const qc = useQueryClient()
  const [params, setParams] = useSearchParams()
  const key = ['billing', workspaceId]
  const q = useQuery({ queryKey: key, queryFn: () => api<{ data: BillingData }>('/workspace/billing') })
  const checkout = useMutation({
    mutationFn: (plan: string) => api<{ data: { authorization_url: string } }>('/workspace/billing/checkout', { method: 'POST', json: { plan } }),
    onSuccess: (r) => { window.location.href = r.data.authorization_url },
  })
  const confirm = useMutation({
    mutationFn: (reference: string) => api<{ data: { status: string; plan: string } }>('/workspace/billing/confirm', { method: 'POST', json: { reference } }),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: key }); void refresh() },
  })
  const confirmed = useRef(false)
  const reference = params.get('reference') ?? params.get('trxref')
  useEffect(() => {
    if (reference && !confirmed.current) { confirmed.current = true; confirm.mutate(reference) }
  }, [reference, confirm])

  if (q.isLoading) return <Spinner />
  if (q.error) return <ErrorState error={q.error} />
  const b = q.data!.data
  const err = fieldErrors(checkout.error).plan?.[0] ?? (checkout.error instanceof Error ? checkout.error.message : null)

  return (
    <div className="stack">
      <h1>Plan and billing</h1>
      {confirm.isPending && <p className="notice">Confirming your payment with Paystack…</p>}
      {confirm.data && (confirm.data.data.status === 'success'
        ? <p className="notice">Payment confirmed. You are now on the {humanize(confirm.data.data.plan)} plan. <button className="btn ghost sm" onClick={() => setParams({})}>Dismiss</button></p>
        : <p className="notice warn">We have not received confirmation of that payment yet. If you were charged, it will update within a few minutes.</p>)}
      <div className="card row between">
        <div>
          <div className="small muted">Current plan</div>
          <strong style={{ fontSize: '1.2rem' }}>{b.plans.find((p) => p.key === b.plan)?.label ?? humanize(b.plan)}</strong> <Badge tone={b.billing_status === 'active' ? 'ok' : b.billing_status === 'past_due' ? 'high' : ''}>{humanize(b.billing_status)}</Badge>
          {b.current_period_end && <div className="small muted">{b.cancel_at_period_end ? 'Ends' : 'Renews'} on {new Date(b.current_period_end).toLocaleDateString()}</div>}
        </div>
        <p className="small muted" style={{ maxWidth: 360, margin: 0 }}>Plans buy coverage: more businesses, locations, pages checked and drafts. They never buy rankings.</p>
      </div>
      {!b.online_payment && <p className="notice warn">Online payment is not set up yet. Contact Findriva to change plans.</p>}
      {err && <p className="error-text">{err}</p>}
      <div className="grid" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(210px, 1fr))', gap: '1rem' }}>
        {b.plans.map((p) => (
          <div key={p.key} className="card stack" style={p.key === b.plan ? { borderColor: 'var(--brand)' } : undefined}>
            <div className="row between"><h3 style={{ margin: 0 }}>{p.label}</h3>{p.key === b.plan && <Badge tone="brand">Current</Badge>}</div>
            <div><strong style={{ fontSize: '1.4rem' }}>{naira(p.price)}</strong>{p.price > 0 && <span className="small muted"> / month</span>}</div>
            <ul className="small" style={{ margin: 0, paddingLeft: '1.1rem' }}>
              <li>{p.businesses} business{p.businesses === 1 ? '' : 'es'}</li>
              <li>{p.locations_per_business} location{p.locations_per_business === 1 ? '' : 's'} each</li>
              <li>{p.pages_per_crawl} pages per audit, {p.crawls_per_month} audits a month</li>
              <li>{p.content_generations_per_day} content drafts a day</li>
              <li>{p.keyword_spend_usd_per_day > 0 ? 'Measured keyword volumes' : 'Keyword ideas (volumes not measured)'}</li>
            </ul>
            {p.price > 0 && b.can_manage && b.online_payment && (p.key !== b.plan || b.cancel_at_period_end || b.billing_status !== 'active') && (
              <button className="btn primary" disabled={checkout.isPending} onClick={() => checkout.mutate(p.key)}>{p.key === b.plan ? 'Renew' : 'Choose'} {p.label}</button>
            )}
          </div>
        ))}
      </div>
      {!b.can_manage && <p className="small muted">Only the workspace owner can change the plan.</p>}
      <p className="small muted">Payments are handled by Paystack (card, bank transfer, USSD). Findriva never sees or stores card details.</p>
      {b.history.length > 0 && (
        <div className="card table-wrap">
          <h3>Payments</h3>
          <table><thead><tr><th>Date</th><th>Plan</th><th>Amount</th><th>Reference</th></tr></thead>
            <tbody>{b.history.map((h) => <tr key={h.reference}><td>{new Date(h.paid_at).toLocaleDateString()}</td><td>{humanize(h.plan)}</td><td>{naira(h.amount)}</td><td className="small">{h.reference}</td></tr>)}</tbody>
          </table>
        </div>
      )}
    </div>
  )
}
