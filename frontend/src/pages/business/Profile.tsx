import { useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, type BusinessDetail } from '../../api'
import { useAuth, useRole } from '../../auth'
import { ErrorState, Field, Badge, fieldErrors, humanize } from '../../ui'

type ChildType = 'locations' | 'contacts' | 'services' | 'products' | 'aliases'

export default function ProfileTab({ detail }: { detail: BusinessDetail }) {
  const b = detail.data
  const { workspaceId } = useAuth()
  const { canEdit } = useRole()
  const qc = useQueryClient()
  const [basics, setBasics] = useState({
    name: b.name, primary_category: b.primary_category ?? '', description: b.description ?? '', website_url: b.website_url ?? '',
  })
  const invalidate = () => {
    void qc.invalidateQueries({ queryKey: ['business', workspaceId, String(b.id)] })
    void qc.invalidateQueries({ queryKey: ['overview', workspaceId, b.id] })
  }

  const save = useMutation({
    mutationFn: () => api(`/businesses/${b.id}`, { method: 'PATCH', json: { ...basics, website_url: basics.website_url || null } }),
    onSuccess: invalidate,
  })
  const addChild = useMutation({ mutationFn: ({ type, body }: { type: ChildType; body: object }) => api(`/businesses/${b.id}/${type}`, { method: 'POST', json: body }), onSuccess: invalidate })
  const removeChild = useMutation({ mutationFn: ({ type, id }: { type: ChildType; id: number }) => api(`/businesses/${b.id}/${type}/${id}`, { method: 'DELETE' }), onSuccess: invalidate })

  const errs = fieldErrors(save.error)
  const [contact, setContact] = useState({ type: 'phone', value: '' })
  const [loc, setLoc] = useState({ city: '', address_line1: '' })
  const [service, setService] = useState('')

  function onSave(e: FormEvent) { e.preventDefault(); save.mutate() }

  return (
    <div className="stack">
      <form className="card stack" onSubmit={onSave}>
        <h2>Basics</h2>
        <div className="grid">
          <Field label="Name" error={errs.name}><input disabled={!canEdit} value={basics.name} onChange={(e) => setBasics({ ...basics, name: e.target.value })} /></Field>
          <Field label="Category"><input disabled={!canEdit} value={basics.primary_category} onChange={(e) => setBasics({ ...basics, primary_category: e.target.value })} /></Field>
          <Field label="Website (optional)" error={errs.website_url}><input disabled={!canEdit} type="url" value={basics.website_url} onChange={(e) => setBasics({ ...basics, website_url: e.target.value })} /></Field>
        </div>
        <Field label="Description"><textarea disabled={!canEdit} rows={3} value={basics.description} onChange={(e) => setBasics({ ...basics, description: e.target.value })} /></Field>
        {canEdit && <div className="row"><button className="btn primary" disabled={save.isPending}>Save</button>{save.isSuccess && <span className="small muted">Saved. Checks updated.</span>}</div>}
      </form>

      {(addChild.error || removeChild.error) && <ErrorState error={addChild.error ?? removeChild.error} />}

      <div className="card stack">
        <h2>Locations</h2>
        {(b.locations ?? []).map((l) => (
          <div key={l.id} className="row between">
            <span>{[l.label, l.address_line1, l.city, l.region].filter(Boolean).join(', ') || humanize(l.location_type)} {l.is_primary && <Badge tone="brand">Primary</Badge>} <Badge>{humanize(l.location_type)}</Badge></span>
            {canEdit && <button className="btn ghost sm danger" onClick={() => removeChild.mutate({ type: 'locations', id: l.id })}>Remove</button>}
          </div>
        ))}
        {canEdit && (
          <form className="row" onSubmit={(e) => { e.preventDefault(); addChild.mutate({ type: 'locations', body: loc }); setLoc({ city: '', address_line1: '' }) }}>
            <input style={{ flex: 2, minWidth: 160 }} placeholder="Street address" value={loc.address_line1} onChange={(e) => setLoc({ ...loc, address_line1: e.target.value })} />
            <input style={{ flex: 1, minWidth: 120 }} placeholder="City" required value={loc.city} onChange={(e) => setLoc({ ...loc, city: e.target.value })} />
            <button className="btn sm">Add location</button>
          </form>
        )}
      </div>

      <div className="card stack">
        <h2>Contacts</h2>
        {(b.contacts ?? []).map((c) => (
          <div key={c.id} className="row between">
            <span><Badge>{humanize(c.type)}</Badge> {c.value} {c.normalized_value && c.normalized_value !== c.value && <span className="muted small">({c.normalized_value})</span>}</span>
            {canEdit && <button className="btn ghost sm danger" onClick={() => removeChild.mutate({ type: 'contacts', id: c.id })}>Remove</button>}
          </div>
        ))}
        {canEdit && (
          <form className="row" onSubmit={(e) => { e.preventDefault(); addChild.mutate({ type: 'contacts', body: contact }); setContact({ ...contact, value: '' }) }}>
            <select style={{ width: 'auto' }} value={contact.type} onChange={(e) => setContact({ ...contact, type: e.target.value })} aria-label="Contact type">
              <option value="phone">Phone</option><option value="whatsapp">WhatsApp</option><option value="email">Email</option>
            </select>
            <input style={{ flex: 1, minWidth: 160 }} required value={contact.value} onChange={(e) => setContact({ ...contact, value: e.target.value })} aria-label="Contact value" />
            <button className="btn sm">Add contact</button>
          </form>
        )}
      </div>

      <div className="card stack">
        <h2>Services</h2>
        {(b.services ?? []).map((s) => (
          <div key={s.id} className="row between"><span>{s.name}</span>{canEdit && <button className="btn ghost sm danger" onClick={() => removeChild.mutate({ type: 'services', id: s.id })}>Remove</button>}</div>
        ))}
        {canEdit && (
          <form className="row" onSubmit={(e) => { e.preventDefault(); addChild.mutate({ type: 'services', body: { name: service } }); setService('') }}>
            <input style={{ flex: 1 }} required placeholder="e.g. Bridal makeup" value={service} onChange={(e) => setService(e.target.value)} aria-label="Service name" />
            <button className="btn sm">Add service</button>
          </form>
        )}
      </div>
    </div>
  )
}
