import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { useAuth } from './auth'
import { Spinner } from './ui'
import { ForgotPasswordPage, LoginPage, RegisterPage, ResetPasswordPage } from './pages/Auth'
import AccountPage from './pages/Account'
import BillingPage from './pages/Billing'
import BusinessesPage from './pages/Businesses'
import OnboardingPage from './pages/Onboarding'
import BusinessPage from './pages/Business'
import WorkspaceShell from './layouts/WorkspaceShell'
// Staff-only code is loaded only when staff open it.
const ControlShell = lazy(() => import('./layouts/ControlShell'))

/** Old operations links keep working: /admin/... now lives at /control/... */
function AdminRedirect() {
  const { pathname, search } = useLocation()
  return <Navigate to={pathname.replace(/^\/admin/, '/control') + search} replace />
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
      <Route path="/control/*" element={<Suspense fallback={<div className="center-page"><Spinner /></div>}><ControlShell /></Suspense>} />
      <Route path="/admin/*" element={<AdminRedirect />} />
      <Route element={<WorkspaceShell />}>
        <Route index element={<BusinessesPage />} />
        <Route path="/businesses/new" element={<OnboardingPage />} />
        <Route path="/businesses/:id/*" element={<BusinessPage />} />
        <Route path="/billing" element={<BillingPage />} />
        <Route path="/account" element={<AccountPage />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
