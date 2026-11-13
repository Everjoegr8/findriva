import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import QRCode from 'qrcode'
import { api } from '../api'
import { useAuth } from '../auth'
import { Badge, ErrorState, Field, Spinner, fieldErrors } from '../ui'

interface Security { email: string; email_verified: boolean; mfa_enabled: boolean; mfa_enabled_at: string | null; recovery_codes_left: number | null }

export default function AccountPage() {
  const { refresh } = useAuth()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['security'], queryFn: () => api<{ data: Security }>('/account/security') })
  const [setup, setSetup] = useState<{ secret: string; otpauth_uri: string } | null>(null)
  const [qr, setQr] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [codes, setCodes] = useState<string[] | null>(null)
  const [password, setPassword] = useState('')
  const done = () => { void qc.invalidateQueries({ queryKey: ['security'] }); void refresh() }
  const begin = useMutation({ mutationFn: () => api<{ data: { secret: string; otpauth_uri: string } }>('/account/mfa/setup', { method: 'POST' }), onSuccess: (r) => setSetup(r.data) })
  const confirm = useMutation({ mutationFn: () => api<{ data: { recovery_codes: string[] } }>('/account/mfa/confirm', { method: 'POST', json: { code } }), onSuccess: (r) => { setCodes(r.data.recovery_codes); setSetup(null); setCode(''); done() } })
  const disable = useMutation({ mutationFn: () => api('/account/mfa/disable', { method: 'POST', json: { password, code } }), onSuccess: () => { setPassword(''); setCode(''); done() } })
  const resend = useMutation({ mutationFn: () => api<{ message: string }>('/auth/email/resend', { method: 'POST' }) })

  useEffect(() => {
    if (!setup) { setQr(null); return }
    QRCode.toDataURL(setup.otpauth_uri, { margin: 1, width: 200 }).then(setQr).catch(() => setQr(null))
  }, [setup])

  if (q.isLoading) return <Spinner />
  if (q.error) return <ErrorState error={q.error} />
  const s = q.data!.data

  return (
    <div className="stack" style={{ maxWidth: 640 }}>
      <h1>Account security</h1>
      <div className="card stack">
        <div className="row between"><h3 style={{ margin: 0 }}>Email</h3>{s.email_verified ? <Badge tone="ok">Confirmed</Badge> : <Badge tone="medium">Not confirmed</Badge>}</div>
        <p className="small" style={{ margin: 0 }}>{s.email}</p>
        {!s.email_verified && <div className="row"><button className="btn ghost sm" disabled={resend.isPending} onClick={() => resend.mutate()}>Send a new confirmation link</button>{resend.data && <span className="small muted">{resend.data.message}</span>}</div>}
      </div>

      <div className="card stack">
        <div className="row between"><h3 style={{ margin: 0 }}>Two-step sign-in</h3>{s.mfa_enabled ? <Badge tone="ok">On</Badge> : <Badge>Off</Badge>}</div>
        <p className="small muted" style={{ margin: 0 }}>After your password, you also enter a code from an authenticator app (Google Authenticator, Microsoft Authenticator, Authy or 1Password). Strongly recommended for owners and admins, who can approve changes and payments.</p>

        {codes && (
          <div className="notice stack" style={{ gap: '0.4rem' }}>
            <strong>Save these recovery codes now.</strong>
            <span className="small">Each works once if you lose your phone. We will not show them again.</span>
            <pre className="code" style={{ whiteSpace: 'pre-wrap' }}>{codes.join('\n')}</pre>
            <div><button className="btn ghost sm" onClick={() => void navigator.clipboard?.writeText(codes.join('\n'))}>Copy codes</button> <button className="btn ghost sm" onClick={() => setCodes(null)}>I saved them</button></div>
          </div>
        )}

        {!s.mfa_enabled && !setup && <div><button className="btn primary" disabled={begin.isPending} onClick={() => begin.mutate()}>Turn on two-step sign-in</button></div>}
        {setup && (
          <div className="stack">
            <ol className="small" style={{ margin: 0, paddingLeft: '1.2rem' }}>
              <li>Scan this code with your authenticator app, or type the key.</li>
              <li>Enter the 6-digit code it shows.</li>
            </ol>
            {qr && <img src={qr} alt="QR code for your authenticator app" width={200} height={200} style={{ background: '#fff', borderRadius: 8 }} />}
            <code className="small" style={{ wordBreak: 'break-all' }}>{setup.secret.replace(/(.{4})/g, '$1 ').trim()}</code>
            <Field label="Code from the app" error={fieldErrors(confirm.error).code}><input inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(e) => setCode(e.target.value)} style={{ maxWidth: 180 }} /></Field>
            <div><button className="btn primary" disabled={confirm.isPending || code.length < 6} onClick={() => confirm.mutate()}>Confirm and turn on</button></div>
          </div>
        )}
        {s.mfa_enabled && (
          <div className="stack">
            <p className="small" style={{ margin: 0 }}>{s.recovery_codes_left} recovery code{s.recovery_codes_left === 1 ? '' : 's'} left.</p>
            <details>
              <summary className="small">Turn off two-step sign-in</summary>
              <div className="stack" style={{ marginTop: '0.5rem' }}>
                <Field label="Password"><input type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} /></Field>
                <Field label="Code from the app (or a recovery code)" error={fieldErrors(disable.error).code}><input value={code} onChange={(e) => setCode(e.target.value)} /></Field>
                <div><button className="btn ghost danger" disabled={disable.isPending} onClick={() => disable.mutate()}>Turn off</button></div>
              </div>
            </details>
          </div>
        )}
      </div>
    </div>
  )
}
