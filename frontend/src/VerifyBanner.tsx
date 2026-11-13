import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api } from './api'
import { useAuth } from './auth'

/** Reminds unconfirmed users, and handles the ?verified= return from the email link. */
export function VerifyBanner() {
  const { user, refresh } = useAuth()
  const [params, setParams] = useSearchParams()
  const [sent, setSent] = useState<string | null>(null)
  const verified = params.get('verified')
  useEffect(() => { if (verified === '1') void refresh() }, [verified, refresh])

  if (verified === '1') return <p className="notice no-print" style={{ marginBottom: '1rem' }}>Thanks, your email is confirmed. <button className="btn ghost sm" onClick={() => setParams({})}>Dismiss</button></p>
  if (!user || user.email_verified) return null
  return (
    <p className="notice warn no-print" style={{ marginBottom: '1rem' }}>
      {verified === 'invalid' ? 'That confirmation link did not work. ' : ''}Please confirm your email ({user.email}) to publish pages, connect accounts, invite people and pay.{' '}
      {sent ? <span>{sent}</span> : <button className="btn ghost sm" onClick={() => void api<{ message: string }>('/auth/email/resend', { method: 'POST' }).then((r) => setSent(r.message)).catch(() => setSent('Could not send right now.'))}>Send the link again</button>}
    </p>
  )
}
