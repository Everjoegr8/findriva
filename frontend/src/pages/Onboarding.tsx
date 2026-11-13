import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, type BusinessDetail } from '../api'
import { useRole } from '../auth'
import { Field, fieldErrors } from '../ui'

const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const
const STEPS = ['Your business', 'Where you operate', 'How customers reach you', 'Where you are online', 'What you offer', 'Review'] as const

interface LocationDraft { label: string; address_line1: string; city: string; region: string; location_type: string; service_areas: string; hours: Record<string, string> }

const emptyLocation = (): LocationDraft => ({ label: '', address_line1: '', city: '', region: '', location_type: 'storefront', service_areas: '', hours: {} })

export default function OnboardingPage() {
  const nav = useNavigate()
  const qc = useQueryClient()
  const { canEdit } = useRole()
  const [step, setStep] = useState(0)
  const [basics, setBasics] = useState({ name: '', primary_category: '', description: '', country: 'NG' })
  const [locations, setLocations] = useState<LocationDraft[]>([emptyLocation()])
  const [contacts, setContacts] = useState({ phone: '', whatsapp: '', email: '' })
  const [website, setWebsite] = useState('')
  const [profiles, setProfiles] = useState<string[]>([''])
  const [services, setServices] = useState('')

  const submit = useMutation({
    mutationFn: () => api<BusinessDetail>('/businesses', { method: 'POST', json: buildPayload() }),
    onSuccess: (r) => { void qc.invalidateQueries({ queryKey: ['businesses'] }); nav(`/businesses/${r.data.id}`) },
  })
  const errs = fieldErrors(submit.error)

  function buildPayload() {
    const locs = locations
      .filter((l) => l.city || l.address_line1 || l.service_areas)
      .map((l) => ({
        label: l.label || undefined, address_line1: l.address_line1 || undefined, city: l.city || undefined, region: l.region || undefined,
        location_type: l.location_type,
        service_areas: l.service_areas ? l.service_areas.split(',').map((s) => s.trim()).filter(Boolean) : undefined,
        opening_hours: Object.keys(l.hours).length ? Object.fromEntries(Object.entries(l.hours).filter(([, v]) => v).map(([d, v]) => [d, [v]])) : undefined,
      }))
    const cs = [
      contacts.phone && { type: 'phone', value: contacts.phone, is_primary: true },
      contacts.whatsapp && { type: 'whatsapp', value: contacts.whatsapp, is_primary: !contacts.phone },
      contacts.email && { type: 'email', value: contacts.email, is_primary: true },
    ].filter(Boolean)
    return {
      ...basics,
      primary_category: basics.primary_category || undefined,
      description: basics.description || undefined,
      website_url: website.trim() || undefined,
      locations: locs,
      contacts: cs,
      assets: profiles.map((p) => p.trim()).filter(Boolean).map((url) => ({ url })),
      services: services.split('\n').map((s) => s.trim()).filter(Boolean).map((name) => ({ name })),
    }
  }

  if (!canEdit) return <div className="card">Your role can view businesses but not add them. Ask a workspace owner for editor access.</div>

  const updateLoc = (i: number, patch: Partial<LocationDraft>) => setLocations(locations.map((l, j) => (j === i ? { ...l, ...patch } : l)))
  const canNext = step !== 0 || basics.name.trim().length > 0

  return (
    <div className="stack" style={{ maxWidth: 760 }}>
      <div>
        <h1>Add a business</h1>
        <p className="muted">Only the name is required. Skip anything you don't have yet; we'll show you what is missing and why it matters.</p>
      </div>
      <div className="steps" aria-label="Progress">
        {STEPS.map((s, i) => <span key={s} className={i === step ? 'on' : i < step ? 'done' : ''}>{i + 1}. {s}</span>)}
      </div>

      <div className="card stack">
        {step === 0 && (
          <>
            <h2>Your business</h2>
            <Field label="Business name" error={errs.name}><input autoFocus value={basics.name} onChange={(e) => setBasics({ ...basics, name: e.target.value })} placeholder="e.g. Glow by Ada" /></Field>
            <Field label="What kind of business is it?" hint="Your main category decides which searches you can appear for, e.g. Makeup artist, Generator repair, Bakery.">
              <input value={basics.primary_category} onChange={(e) => setBasics({ ...basics, primary_category: e.target.value })} />
            </Field>
            <Field label="Describe it in two or three sentences" hint="What you offer, who it's for and where.">
              <textarea rows={3} value={basics.description} onChange={(e) => setBasics({ ...basics, description: e.target.value })} />
            </Field>
            <Field label="Country">
              <select value={basics.country} onChange={(e) => setBasics({ ...basics, country: e.target.value })}>
                <option value="NG">Nigeria</option><option value="GH">Ghana</option><option value="KE">Kenya</option><option value="ZA">South Africa</option><option value="GB">United Kingdom</option><option value="US">United States</option>
              </select>
            </Field>
          </>
        )}

        {step === 1 && (
          <>
            <h2>Where you operate</h2>
            <p className="muted small">Add a shop or office customers visit, or the areas you travel to. Both help you show up for "near me" searches.</p>
            {locations.map((l, i) => (
              <div key={i} className="stack card" style={{ boxShadow: 'none' }}>
                <div className="row between"><strong>Location {i + 1}</strong>{locations.length > 1 && <button className="btn ghost sm danger" onClick={() => setLocations(locations.filter((_, j) => j !== i))}>Remove</button>}</div>
                <Field label="Type">
                  <select value={l.location_type} onChange={(e) => updateLoc(i, { location_type: e.target.value })}>
                    <option value="storefront">Customers visit us</option><option value="service_area">We go to customers</option><option value="hybrid">Both</option><option value="online">Online only</option>
                  </select>
                </Field>
                <div className="grid">
                  <Field label="Name (optional)"><input value={l.label} onChange={(e) => updateLoc(i, { label: e.target.value })} placeholder="Lekki studio" /></Field>
                  <Field label="Street address"><input value={l.address_line1} onChange={(e) => updateLoc(i, { address_line1: e.target.value })} /></Field>
                  <Field label="City / town"><input value={l.city} onChange={(e) => updateLoc(i, { city: e.target.value })} placeholder="Lagos" /></Field>
                  <Field label="State"><input value={l.region} onChange={(e) => updateLoc(i, { region: e.target.value })} /></Field>
                </div>
                {l.location_type !== 'storefront' && (
                  <Field label="Areas you serve" hint="Separate with commas"><input value={l.service_areas} onChange={(e) => updateLoc(i, { service_areas: e.target.value })} placeholder="Ajah, Ikoyi, Victoria Island" /></Field>
                )}
                <details>
                  <summary className="small">Opening hours</summary>
                  <div className="grid" style={{ marginTop: '0.5rem' }}>
                    {DAYS.map((d) => (
                      <Field key={d} label={d.toUpperCase()}><input placeholder="09:00-18:00" value={l.hours[d] ?? ''} onChange={(e) => updateLoc(i, { hours: { ...l.hours, [d]: e.target.value } })} /></Field>
                    ))}
                  </div>
                </details>
              </div>
            ))}
            <button className="btn sm" onClick={() => setLocations([...locations, emptyLocation()])}>Add another location</button>
          </>
        )}

        {step === 2 && (
          <>
            <h2>How customers reach you</h2>
            <p className="muted small">Use the same details you use everywhere else. Consistency builds trust with customers and search engines.</p>
            <Field label="Phone"><input type="tel" value={contacts.phone} onChange={(e) => setContacts({ ...contacts, phone: e.target.value })} placeholder="0803 123 4567" /></Field>
            <Field label="WhatsApp"><input type="tel" value={contacts.whatsapp} onChange={(e) => setContacts({ ...contacts, whatsapp: e.target.value })} /></Field>
            <Field label="Email"><input type="email" value={contacts.email} onChange={(e) => setContacts({ ...contacts, email: e.target.value })} /></Field>
          </>
        )}

        {step === 3 && (
          <>
            <h2>Where you are online</h2>
            <Field label="Website (optional)" hint="Don't have one? That's fine. We'll start with the profiles you have." error={errs.website_url}>
              <input type="url" value={website} onChange={(e) => setWebsite(e.target.value)} placeholder="https://" />
            </Field>
            <Field label="Profiles and storefronts" hint="Paste links to Instagram, Facebook, TikTok, WhatsApp Business, Google Business Profile, Shopify, Jumia, directories…">
              <div className="stack">
                {profiles.map((p, i) => (
                  <div className="row" key={i} style={{ flexWrap: 'nowrap' }}>
                    <input type="url" value={p} onChange={(e) => setProfiles(profiles.map((x, j) => (j === i ? e.target.value : x)))} placeholder="https://instagram.com/yourbusiness" />
                    {profiles.length > 1 && <button className="btn ghost sm" aria-label="Remove link" onClick={() => setProfiles(profiles.filter((_, j) => j !== i))}>✕</button>}
                  </div>
                ))}
                {Object.keys(errs).filter((k) => k.startsWith('assets.')).map((k) => <span key={k} className="error-text">{errs[k][0]}</span>)}
                <button className="btn sm" style={{ alignSelf: 'flex-start' }} onClick={() => setProfiles([...profiles, ''])}>Add another link</button>
              </div>
            </Field>
            <p className="small muted">We only read public pages. We never ask for your social media or Google passwords; any connection is made by you, through the platform's own sign-in.</p>
          </>
        )}

        {step === 4 && (
          <>
            <h2>What you offer</h2>
            <Field label="Your main services or products" hint="One per line. Each one is something customers search for.">
              <textarea rows={6} value={services} onChange={(e) => setServices(e.target.value)} placeholder={'Bridal makeup\nEvent makeup\nMakeup classes'} />
            </Field>
          </>
        )}

        {step === 5 && (
          <>
            <h2>Review</h2>
            <dl className="qa">
              <dt>Name</dt><dd>{basics.name}</dd>
              <dt>Category</dt><dd>{basics.primary_category || <span className="muted">Not set</span>}</dd>
              <dt>Locations</dt><dd>{buildPayload().locations.length || <span className="muted">None yet</span>}</dd>
              <dt>Contacts</dt><dd>{buildPayload().contacts.length || <span className="muted">None yet</span>}</dd>
              <dt>Website</dt><dd>{website || <span className="muted">No website (optional)</span>}</dd>
              <dt>Profiles</dt><dd>{buildPayload().assets.length}</dd>
              <dt>Services</dt><dd>{buildPayload().services.length}</dd>
            </dl>
            <div className="notice">
              {website ? 'When you finish, we will check your website and profiles and build your first action plan. This usually takes a minute or two.' : 'When you finish, we will check your profile and the links you added, and build your first action plan.'}
            </div>
            <p className="small muted">We can't guarantee rankings or a top position: search engines decide that. What we do is fix what's holding you back, publish improvements you approve, and measure what changes.</p>
            {submit.error && <p className="error-text">{(submit.error as Error).message}</p>}
          </>
        )}

        <div className="row between">
          <button className="btn" disabled={step === 0} onClick={() => setStep(step - 1)}>Back</button>
          {step < STEPS.length - 1
            ? <button className="btn primary" disabled={!canNext} onClick={() => setStep(step + 1)}>Continue</button>
            : <button className="btn primary" disabled={submit.isPending} onClick={() => submit.mutate()}>{submit.isPending ? 'Saving…' : 'Finish and run checks'}</button>}
        </div>
      </div>
    </div>
  )
}
