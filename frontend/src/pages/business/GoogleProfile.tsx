import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type Business } from '../../api'
import { useAuth, useRole } from '../../auth'
import { Badge, Empty, ErrorState, Spinner, fieldErrors, humanize } from '../../ui'

interface Location { name: string; account: string; title: string; address: string | null; phone: string | null; website: string | null }
interface Connection {
  id: number; status: string; account_email: string | null; location: string | null; available_locations: Location[]
  last_synced_at: string | null; data_through: string | null; last_error: string | null
}
type Totals = Record<'calls' | 'website_clicks' | 'directions' | 'messages' | 'search_views' | 'maps_views', number>
interface ProfileData {
  available: boolean
  connection: Connection | null
  profile: { title: string | null; phone: string | null; website: string | null; primary_category: string | null; address: string | null; maps_uri: string | null; review_uri: string | null } | null
  differences: { field: string; label: string; findriva: string | null; google: string | null }[]
  metrics: { period: { start: string; end: string; days: number }; totals: Totals; previous: Totals | null; daily: { date: string; actions: number }[] } | null
  reviews: { count: number; rated: number; average_rating: number | null; unanswered: number; awaiting_approval: number; last_30_days: number } | null
  can_approve: boolean
}
interface Flag { severity: string; kind: string; text: string; message: string }
interface Review {
  id: number; reviewer_name: string | null; rating: number | null; comment: string | null; reviewed_at: string | null
  reply: string | null; reply_status: string; reply_draft: string | null; draft_check: { flags: Flag[]; blocking: boolean } | null
  reply_error: string | null; posted_at: string | null
}

const METRICS: [keyof Totals, string][] = [
  ['calls', 'Calls'], ['directions', 'Direction requests'], ['website_clicks', 'Website clicks'],
  ['messages', 'Chats'], ['search_views', 'Views on Google Search'], ['maps_views', 'Views on Google Maps'],
]
const DIFF_HELP: Record<string, string> = {
  name: 'Use the same name everywhere so people and Google know it is one business.',
  phone: 'Customers call the number on Google first. Make sure it is the one you answer.',
  website: 'Point your profile to the website you use now.',
  hours: 'Add opening hours. Profiles without hours look closed or inactive.',
  description: 'Add a short description of what you offer and where.',
}

const short = (s: string | null) => (s && s.length > 120 ? `${s.slice(0, 117).trimEnd()}…` : s)

function Stars({ value }: { value: number | null }) {
  if (value === null) return <span className="small muted">No rating</span>
  return <span aria-label={`${value} out of 5 stars`} style={{ color: 'var(--medium)', letterSpacing: 1 }}>{'★'.repeat(value)}<span className="muted">{'★'.repeat(5 - value)}</span></span>
}

function Delta({ now, prev }: { now: number; prev: number | undefined }) {
  if (prev === undefined) return null
  if (prev === 0) return now > 0 ? <div className="small muted">none in the previous period</div> : null
  const pct = Math.round(((now - prev) / prev) * 100)
  return <div className={`small ${pct >= 0 ? '' : 'muted'}`}>{pct >= 0 ? '▲' : '▼'} {Math.abs(pct)}% vs previous period</div>
}

export default function GoogleProfileTab({ business }: { business: Business }) {
  const { workspaceId } = useAuth()
  const qc = useQueryClient()
  const [days, setDays] = useState(28)
  const key = ['google-profile', workspaceId, business.id, days]
  const q = useQuery({ queryKey: key, queryFn: () => api<{ data: ProfileData }>(`/businesses/${business.id}/google-profile?days=${days}`) })
  const refresh = () => { void qc.invalidateQueries({ queryKey: ['google-profile', workspaceId, business.id] }); void qc.invalidateQueries({ queryKey: ['reviews', workspaceId, business.id] }) }
  const params = new URLSearchParams(window.location.search)

  if (q.isLoading) return <Spinner />
  if (q.error) return <ErrorState error={q.error} retry={() => void q.refetch()} />
  const d = q.data!.data
  const c = d.connection

  return (
    <div className="stack">
      <p className="muted">
        Your Google Business Profile is what people see on Google Maps and in local results. Connect it to compare it with your details,
        see what people did on it, and answer reviews. Replies are posted only after an owner or admin approves them.
      </p>
      {params.get('connected') === 'google_business_profile' && <p className="notice">Connected. The first import takes a minute.</p>}
      {params.get('integration_error') && <p className="notice warn">Google did not finish connecting ({humanize(params.get('integration_error')!)}). Please try again.</p>}

      <ConnectionCard business={business} data={d} onChange={refresh} />

      {c?.location && (
        <>
          {d.differences.length > 0 && (
            <div className="card stack">
              <h3 style={{ margin: 0 }}>Fix on your Google profile</h3>
              <p className="small muted" style={{ margin: 0 }}>Where Google shows something different from your details in Findriva. Change it in Google Business Profile, then press “Import latest data” to check again.</p>
              <ul style={{ margin: 0, paddingLeft: '1.1rem' }}>
                {d.differences.map((x) => (
                  <li key={x.field} className="small" style={{ marginBottom: '0.4rem', overflowWrap: 'anywhere' }}>
                    <strong>{x.label}</strong>
                    {x.google !== null || x.findriva !== null
                      ? <>: Google shows <em>{short(x.google) ?? 'nothing'}</em>, your details say <em>{short(x.findriva) ?? 'nothing'}</em>.</>
                      : <> is missing on Google.</>}
                    <div className="muted">{DIFF_HELP[x.field]}</div>
                  </li>
                ))}
              </ul>
            </div>
          )}

          {d.metrics ? (
            <section className="stack">
              <div className="row between">
                <h3 style={{ margin: 0 }}>What people did on your profile</h3>
                <div className="chips" role="group" aria-label="Period">
                  {[7, 28, 90].map((n) => <button key={n} aria-pressed={days === n} onClick={() => setDays(n)}>{n} days</button>)}
                </div>
              </div>
              <div className="grid" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: '0.75rem' }}>
                {METRICS.map(([k, label]) => (
                  <div key={k} className="card stat">
                    <div className="label">{label}</div>
                    <div className="value">{d.metrics!.totals[k].toLocaleString()}</div>
                    <Delta now={d.metrics!.totals[k]} prev={d.metrics!.previous?.[k]} />
                  </div>
                ))}
              </div>
              <p className="small muted" style={{ margin: 0 }}>
                From Google Business Profile, {d.metrics.period.start} to {d.metrics.period.end}. Google reports these a few days late and counts each tap, not each customer.
              </p>
            </section>
          ) : <p className="small muted">Profile actions appear after the first import.</p>}

          <ReviewsSection business={business} data={d} onChange={refresh} />
        </>
      )}
    </div>
  )
}

function ConnectionCard({ business, data, onChange }: { business: Business; data: ProfileData; onChange: () => void }) {
  const c = data.connection
  const connect = useMutation({
    mutationFn: () => api<{ data: { authorization_url: string } }>(`/businesses/${business.id}/integrations/google-business/connect`, { method: 'POST' }),
    onSuccess: (r) => { window.location.href = r.data.authorization_url },
  })
  const choose = useMutation({ mutationFn: (property: string) => api(`/businesses/${business.id}/integrations/${c!.id}`, { method: 'PATCH', json: { property } }), onSuccess: onChange })
  const sync = useMutation({ mutationFn: () => api(`/businesses/${business.id}/integrations/${c!.id}/sync`, { method: 'POST' }), onSuccess: onChange })
  const disconnect = useMutation({ mutationFn: () => api(`/businesses/${business.id}/integrations/${c!.id}`, { method: 'DELETE' }), onSuccess: onChange })
  const err = fieldErrors(connect.error).google?.[0] ?? (connect.error instanceof Error ? connect.error.message : null)

  return (
    <div className="card stack">
      <div className="row between">
        <h3 style={{ margin: 0 }}>Google Business Profile</h3>
        {c ? <Badge tone={c.status === 'connected' ? 'ok' : 'medium'}>{humanize(c.status)}</Badge> : <Badge>Not connected</Badge>}
      </div>
      {!c && (
        <>
          <p className="small muted" style={{ margin: 0 }}>You sign in with Google and choose the profile. Findriva never sees your password, and you can remove access at any time from your Google account.</p>
          {data.available
            ? (data.can_approve ? <div><button className="btn primary" disabled={connect.isPending} onClick={() => connect.mutate()}>Connect with Google</button></div> : <p className="small muted">Ask an owner or admin to connect it.</p>)
            : <p className="small muted">Google sign-in is not set up on this Findriva installation yet.</p>}
        </>
      )}
      {err && <p className="error-text">{err}</p>}
      {c && (
        <div className="stack" style={{ gap: '0.5rem' }}>
          <div className="small" style={{ overflowWrap: 'anywhere' }}>
            {c.account_email && <>Signed in as <strong>{c.account_email}</strong>. </>}
            {data.profile?.title && <>Profile: <strong>{data.profile.title}</strong>{data.profile.address ? `, ${data.profile.address}` : ''}. </>}
            {data.profile?.maps_uri && <a href={data.profile.maps_uri} target="_blank" rel="noreferrer noopener">Open on Google Maps</a>}
          </div>
          {c.last_synced_at && <div className="small muted">Last import {new Date(c.last_synced_at).toLocaleString()}.</div>}
          {c.status === 'needs_property' && (
            <label className="small">Which profile is this business?
              <select defaultValue="" onChange={(e) => e.target.value && choose.mutate(e.target.value)} disabled={!data.can_approve}>
                <option value="">Choose a profile</option>
                {c.available_locations.map((l) => <option key={l.name} value={l.name}>{l.title}{l.address ? ` (${l.address})` : ''}</option>)}
              </select>
            </label>
          )}
          {c.last_error && <p className="notice warn" style={{ margin: 0 }}>{c.last_error}</p>}
          <div className="row" style={{ flexWrap: 'wrap' }}>
            {c.status === 'revoked' && data.can_approve && <button className="btn primary sm" onClick={() => connect.mutate()}>Reconnect</button>}
            {c.location && c.status !== 'revoked' && <button className="btn ghost sm" disabled={sync.isPending} onClick={() => sync.mutate()}>{sync.isSuccess ? 'Import queued' : 'Import latest data'}</button>}
            {data.can_approve && <button className="btn ghost sm danger" disabled={disconnect.isPending} onClick={() => disconnect.mutate()}>Disconnect and delete imported data</button>}
          </div>
        </div>
      )}
    </div>
  )
}

function ReviewsSection({ business, data, onChange }: { business: Business; data: ProfileData; onChange: () => void }) {
  const { workspaceId } = useAuth()
  const [filter, setFilter] = useState('unanswered')
  const q = useQuery({
    queryKey: ['reviews', workspaceId, business.id, filter],
    queryFn: () => api<{ data: Review[]; meta: { total: number } }>(`/businesses/${business.id}/reviews?filter=${filter}&per_page=50`),
  })
  const r = data.reviews

  return (
    <section className="stack">
      <h3 style={{ margin: 0 }}>Reviews</h3>
      {r && (
        <div className="grid" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: '0.75rem' }}>
          <div className="card stat"><div className="label">Average rating</div><div className="value">{r.average_rating ?? '–'}</div><div className="small muted">from {r.rated} ratings</div></div>
          <div className="card stat"><div className="label">New in 30 days</div><div className="value">{r.last_30_days}</div></div>
          <div className="card stat"><div className="label">Not answered</div><div className="value">{r.unanswered}</div></div>
          <div className="card stat"><div className="label">Replies to approve</div><div className="value">{r.awaiting_approval}</div></div>
        </div>
      )}
      <div className="chips" role="group" aria-label="Filter reviews">
        {[['unanswered', 'Not answered'], ['awaiting_approval', 'To approve'], ['low', '3 stars or less'], ['all', 'All']].map(([v, l]) => (
          <button key={v} aria-pressed={filter === v} onClick={() => setFilter(v)}>{l}</button>
        ))}
      </div>
      {q.isLoading ? <Spinner /> : q.error ? <ErrorState error={q.error} /> : q.data!.data.length === 0 ? (
        <Empty title="Nothing here">{filter === 'unanswered' ? 'Every review has a reply.' : 'No reviews match this filter.'}</Empty>
      ) : (
        <div className="stack">
          {q.data!.data.map((rv) => <ReviewCard key={rv.id} business={business} review={rv} canApprove={data.can_approve} onChange={onChange} />)}
        </div>
      )}
      {data.profile?.review_uri && <p className="small muted">Share your review link with happy customers: <a href={data.profile.review_uri} target="_blank" rel="noreferrer noopener">{data.profile.review_uri}</a>. Google does not allow offering anything in return for reviews.</p>}
    </section>
  )
}

function ReviewCard({ business, review, canApprove, onChange }: { business: Business; review: Review; canApprove: boolean; onChange: () => void }) {
  const { canEdit } = useRole()
  const [text, setText] = useState(review.reply_draft ?? '')
  const [editing, setEditing] = useState(false)
  const base = `/businesses/${business.id}/reviews/${review.id}`
  const suggest = useMutation({ mutationFn: () => api<{ data: { text: string } }>(`${base}/suggestion`), onSuccess: (r) => { setText(r.data.text); setEditing(true) } })
  const save = useMutation({ mutationFn: () => api(base, { method: 'PATCH', json: { reply: text } }), onSuccess: () => { setEditing(false); onChange() } })
  const submit = useMutation({ mutationFn: () => api(`${base}/submit`, { method: 'POST' }), onSuccess: onChange })
  const decide = useMutation({ mutationFn: (decision: string) => api(`${base}/decision`, { method: 'POST', json: { decision } }), onSettled: onChange })
  const error = [save.error, submit.error, decide.error].map((e) => e && (fieldErrors(e).reply?.[0] ?? (e as Error).message)).find(Boolean)
  const flags = review.draft_check?.flags ?? []
  const locked = ['awaiting_approval', 'posting'].includes(review.reply_status)

  return (
    <div className="card stack" style={{ gap: '0.5rem' }}>
      <div className="row between" style={{ alignItems: 'flex-start' }}>
        <div>
          <strong>{review.reviewer_name ?? 'A Google user'}</strong> <Stars value={review.rating} />
          <div className="small muted">{review.reviewed_at ? new Date(review.reviewed_at).toLocaleDateString() : ''}</div>
        </div>
        {review.reply_status !== 'none' && <Badge tone={review.reply_status === 'posted' ? 'ok' : review.reply_status === 'failed' ? 'critical' : review.reply_status === 'awaiting_approval' ? 'medium' : ''}>
          {{ draft: 'Draft reply', awaiting_approval: 'Reply waiting for approval', posting: 'Posting', posted: 'Answered', failed: 'Posting failed' }[review.reply_status] ?? humanize(review.reply_status)}
        </Badge>}
      </div>
      {review.comment ? <p style={{ margin: 0, whiteSpace: 'pre-wrap' }}>{review.comment}</p> : <p className="small muted" style={{ margin: 0 }}>Rating only, no text.</p>}
      {review.reply && (
        <div className="small" style={{ borderLeft: '3px solid var(--brand)', paddingLeft: '0.75rem' }}>
          <div className="muted">Your reply on Google</div>
          <div style={{ whiteSpace: 'pre-wrap' }}>{review.reply}</div>
        </div>
      )}

      {!review.reply && canEdit && (
        editing || (review.reply_draft && !locked && review.reply_status !== 'failed') ? (
          <div className="stack" style={{ gap: '0.5rem' }}>
            <textarea rows={4} value={text} onChange={(e) => setText(e.target.value)} aria-label="Your reply" maxLength={4000} />
            <div className="row" style={{ flexWrap: 'wrap' }}>
              <button className="btn sm" disabled={save.isPending || !text.trim()} onClick={() => save.mutate()}>Save draft</button>
              {review.reply_draft && review.reply_status === 'draft' && !editing && (
                <button className="btn primary sm" disabled={submit.isPending || review.draft_check?.blocking} onClick={() => submit.mutate()}>Send for approval</button>
              )}
              {editing && <button className="btn ghost sm" onClick={() => { setEditing(false); setText(review.reply_draft ?? '') }}>Cancel</button>}
            </div>
          </div>
        ) : !locked && review.reply_status !== 'failed' && (
          <div className="row">
            <button className="btn ghost sm" disabled={suggest.isPending} onClick={() => suggest.mutate()}>Suggest a reply</button>
            <button className="btn ghost sm" onClick={() => setEditing(true)}>Write my own</button>
          </div>
        )
      )}

      {review.reply_draft && (locked || review.reply_status === 'failed') && (
        <div className="small" style={{ borderLeft: '3px solid var(--medium)', paddingLeft: '0.75rem' }}>
          <div className="muted">Reply to be posted</div>
          <div style={{ whiteSpace: 'pre-wrap' }}>{review.reply_draft}</div>
        </div>
      )}
      {flags.length > 0 && !review.reply && (
        <ul className="small" style={{ margin: 0, paddingLeft: '1.1rem' }}>
          {flags.map((f, i) => <li key={i} className={f.severity === 'block' ? 'error-text' : 'muted'}>{f.text && <strong>{f.text}: </strong>}{f.message}</li>)}
        </ul>
      )}
      {review.reply_error && <p className="notice warn" style={{ margin: 0 }}>{review.reply_error}</p>}
      {(review.reply_status === 'awaiting_approval' || review.reply_status === 'failed') && (
        canApprove ? (
          <div className="row">
            <button className="btn primary sm" disabled={decide.isPending} onClick={() => decide.mutate('approve')}>{review.reply_status === 'failed' ? 'Try posting again' : 'Approve and post to Google'}</button>
            {review.reply_status === 'awaiting_approval' && <button className="btn ghost sm" disabled={decide.isPending} onClick={() => decide.mutate('reject')}>Send back</button>}
          </div>
        ) : <p className="small muted" style={{ margin: 0 }}>An owner or admin needs to approve this before it is posted.</p>
      )}
      {error && <p className="error-text">{error}</p>}
    </div>
  )
}
