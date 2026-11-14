import { useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api, type User } from '../api'
import { useAuth } from '../auth'
import { Field, fieldErrors } from '../ui'

function AuthFrame({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="center-page">
      <div className="auth-card stack">
        <div className="row"><img src="/favicon.svg" alt="" width={36} height={36} /><strong style={{ fontSize: '1.3rem' }}>Findriva</strong></div>
        <p className="muted">Your business deserves to be found. We help you build the presence, publish the improvements and measure what changes.</p>
        <div className="card stack">
          <h1>{title}</h1>
          {children}
        </div>
        <div className="auth-links small"><a href="/">About Findriva</a><a href="/privacy/">Privacy</a><a href="/terms/">Terms</a></div>
      </div>
    </div>
  )
}

export function LoginPage() {
  const { signIn } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [challenge, setChallenge] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [busy, setBusy] = useState(false)

  async function submit(e: FormEvent) {
    e.preventDefault(); setBusy(true); setError(null)
    try {
      if (challenge) {
        const r = await api<{ token: string; user: User }>('/auth/mfa/challenge', { method: 'POST', json: { challenge, code } })
        signIn(r.token, r.user)
        return
      }
      const r = await api<{ token?: string; user?: User; mfa_required?: boolean; challenge?: string }>('/auth/login', { method: 'POST', json: { email, password } })
      if (r.mfa_required && r.challenge) setChallenge(r.challenge)
      else if (r.token && r.user) signIn(r.token, r.user)
    } catch (err) {
      if (challenge && fieldErrors(err).challenge) { setChallenge(null); setCode('') }
      setError(err)
    } finally { setBusy(false) }
  }
  const errs = fieldErrors(error)
  return (
    <AuthFrame title={challenge ? 'Enter your code' : 'Sign in'}>
      <form className="stack" onSubmit={submit}>
        {challenge ? (
          <Field label="6-digit code from your authenticator app" hint="Lost your phone? Enter one of your recovery codes instead." error={errs.code}>
            <input inputMode="numeric" autoComplete="one-time-code" autoFocus required value={code} onChange={(e) => setCode(e.target.value)} />
          </Field>
        ) : (
          <>
            <Field label="Email" error={errs.email}><input type="email" autoComplete="email" required value={email} onChange={(e) => setEmail(e.target.value)} /></Field>
            <Field label="Password"><input type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} /></Field>
          </>
        )}
        {error && !errs.email && !errs.code ? <p className="error-text">{errs.challenge?.[0] ?? (error as Error).message}</p> : null}
        <button className="btn primary" disabled={busy}>{busy ? 'Checking…' : challenge ? 'Continue' : 'Sign in'}</button>
        {!challenge && <p className="small muted"><Link to="/forgot-password">Forgot your password?</Link> · New here? <Link to="/register">Create an account</Link></p>}
      </form>
    </AuthFrame>
  )
}

export function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [sent, setSent] = useState<string | null>(null)
  const [error, setError] = useState<unknown>(null)
  async function submit(e: FormEvent) {
    e.preventDefault(); setError(null)
    try { setSent((await api<{ message: string }>('/auth/password/forgot', { method: 'POST', json: { email } })).message) } catch (err) { setError(err) }
  }
  return (
    <AuthFrame title="Reset your password">
      {sent ? <p className="notice">{sent}</p> : (
        <form className="stack" onSubmit={submit}>
          <Field label="Email" error={fieldErrors(error).email}><input type="email" required autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} /></Field>
          <button className="btn primary">Send reset link</button>
        </form>
      )}
      <p className="small muted"><Link to="/login">Back to sign in</Link></p>
    </AuthFrame>
  )
}

export function ResetPasswordPage() {
  const [params] = useSearchParams()
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [done, setDone] = useState<string | null>(null)
  const [error, setError] = useState<unknown>(null)
  async function submit(e: FormEvent) {
    e.preventDefault(); setError(null)
    try {
      const r = await api<{ message: string }>('/auth/password/reset', { method: 'POST', json: { token: params.get('token'), email: params.get('email'), password, password_confirmation: confirm } })
      setDone(r.message)
    } catch (err) { setError(err) }
  }
  const errs = fieldErrors(error)
  return (
    <AuthFrame title="Choose a new password">
      {done ? <p className="notice">{done} <Link to="/login">Sign in</Link></p> : (
        <form className="stack" onSubmit={submit}>
          <Field label="New password" hint="At least 10 characters, with letters and numbers." error={errs.password}><input type="password" required autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} /></Field>
          <Field label="Repeat new password"><input type="password" required autoComplete="new-password" value={confirm} onChange={(e) => setConfirm(e.target.value)} /></Field>
          {errs.email && <p className="error-text">{errs.email[0]}</p>}
          <button className="btn primary">Change password</button>
        </form>
      )}
    </AuthFrame>
  )
}

export function RegisterPage() {
  const { signIn } = useAuth()
  const [form, setForm] = useState({ name: '', email: '', password: '', workspace_name: '', workspace_type: 'business' })
  const [error, setError] = useState<unknown>(null)
  const [busy, setBusy] = useState(false)
  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setForm({ ...form, [k]: e.target.value })

  async function submit(e: FormEvent) {
    e.preventDefault(); setBusy(true); setError(null)
    try {
      const r = await api<{ token: string; user: User }>('/auth/register', { method: 'POST', json: { ...form, workspace_name: form.workspace_name || undefined } })
      signIn(r.token, r.user)
    } catch (err) { setError(err) } finally { setBusy(false) }
  }
  const errs = fieldErrors(error)
  return (
    <AuthFrame title="Create your account">
      <form className="stack" onSubmit={submit}>
        <Field label="Your name" error={errs.name}><input required value={form.name} onChange={set('name')} autoComplete="name" /></Field>
        <Field label="Email" error={errs.email}><input type="email" required value={form.email} onChange={set('email')} autoComplete="email" /></Field>
        <Field label="Password" hint="At least 10 characters, with letters and numbers." error={errs.password}>
          <input type="password" required value={form.password} onChange={set('password')} autoComplete="new-password" />
        </Field>
        <Field label="Workspace name" hint="Usually your company name." error={errs.workspace_name}><input value={form.workspace_name} onChange={set('workspace_name')} /></Field>
        <Field label="I am…">
          <select value={form.workspace_type} onChange={set('workspace_type')}>
            <option value="business">A business owner</option>
            <option value="agency">An agency managing clients</option>
          </select>
        </Field>
        <button className="btn primary" disabled={busy}>{busy ? 'Creating…' : 'Create account'}</button>
        <p className="small muted">Already have an account? <Link to="/login">Sign in</Link></p>
      </form>
    </AuthFrame>
  )
}
