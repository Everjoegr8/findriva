import { Navigate, NavLink, Outlet, Route, Routes, useLocation } from 'react-router-dom'
import { useAuth } from './auth'
import { Spinner } from './ui'
import { ForgotPasswordPage, LoginPage, RegisterPage, ResetPasswordPage } from './pages/Auth'
import AccountPage from './pages/Account'
import BillingPage from './pages/Billing'
import { VerifyBanner } from './VerifyBanner'
import BusinessesPage from './pages/Businesses'
import OnboardingPage from './pages/Onboarding'
import BusinessPage from './pages/Business'
import AdminPage from './pages/Admin'
import LandingPage from './pages/Landing'

function Shell() {
  const { user, workspaceId, setWorkspace, signOut } = useAuth()
  const { pathname } = useLocation()
  if (!user) return pathname === '/' ? <LandingPage /> : <Navigate to="/login" replace />
  return (
    <div className="shell">
      <aside className="sidebar">
        <NavLink to="/" className="brand"><img src="/favicon.svg" alt="" /> Findriva</NavLink>
        {user.workspaces.length > 1 && (
          <select aria-label="Workspace" value={workspaceId ?? ''} onChange={(e) => setWorkspace(Number(e.target.value))}>
            {user.workspaces.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
          </select>
        )}
        <nav className="nav">
          <NavLink to="/" end>Businesses</NavLink>
          <NavLink to="/businesses/new">Add a business</NavLink>
          <NavLink to="/billing">Plan and billing</NavLink>
          <NavLink to="/account">Account security</NavLink>
          {user.is_platform_admin && <NavLink to="/admin">Operations</NavLink>}
        </nav>
        <div style={{ marginTop: 'auto' }} className="small muted">
          {user.name}<br />
          <button className="btn ghost sm" onClick={() => void signOut()}>Sign out</button>
        </div>
      </aside>
      <main className="main"><VerifyBanner /><Outlet /></main>
    </div>
  )
}

export default function App() {
  const { loading, user } = useAuth()
  if (loading) return <div className="center-page"><Spinner /></div>
  return (
    <Routes>
      <Route path="/login" element={user ? <Navigate to="/" replace /> : <LoginPage />} />
      <Route path="/register" element={user ? <Navigate to="/" replace /> : <RegisterPage />} />
      <Route path="/forgot-password" element={<ForgotPasswordPage />} />
      <Route path="/reset-password" element={<ResetPasswordPage />} />
      <Route element={<Shell />}>
        <Route index element={<BusinessesPage />} />
        <Route path="/businesses/new" element={<OnboardingPage />} />
        <Route path="/businesses/:id/*" element={<BusinessPage />} />
        <Route path="/admin" element={<AdminPage />} />
        <Route path="/billing" element={<BillingPage />} />
        <Route path="/account" element={<AccountPage />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
