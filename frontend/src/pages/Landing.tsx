import { Link } from 'react-router-dom'

const features: [string, string][] = [
  ['Find what is holding you back', 'A check of your website, Google profile, social pages and listings, with a clear list of what to fix first.'],
  ['Fix it with ready-made help', 'Search-engine files, business details for Google, short pages and posts, all written only from facts you approve.'],
  ['See where customers find you', 'Google search positions, your spot on Google Maps around your shop, and which competitors show up instead.'],
  ['Know what AI assistants say', 'We ask AI assistants the questions your customers ask and show whether they name you.'],
  ['Answer reviews with care', 'Your Google reviews in one inbox, with suggested replies that an owner approves before anything is posted.'],
  ['Measure what changed', 'Clicks, searches, calls and direction requests from the accounts you connect, in a monthly report.'],
]

const plans: [string, string, string][] = [
  ['Free snapshot', '₦0', 'One business: audits, your action list and ready-made fixes.'],
  ['Starter', '₦9,900 / month', 'One business, plus keyword data, weekly rankings, map checks and AI answer checks.'],
  ['Growth', '₦29,900 / month', 'Up to 5 businesses, with higher limits on every check.'],
  ['Agency', '₦99,000 / month', 'Up to 50 client businesses for agencies and teams.'],
]

export default function LandingPage() {
  return (
    <div className="landing">
      <header className="landing-bar">
        <Link to="/" className="brand"><img src="/favicon.svg" alt="" /> Findriva</Link>
        <nav className="row"><Link to="/login">Sign in</Link><Link className="btn primary sm" to="/register">Get started free</Link></nav>
      </header>

      <section className="landing-hero">
        <h1>Help customers find, trust and contact your business</h1>
        <p className="lead">
          Findriva checks how your business shows up on Google, Maps, social media and AI assistants, tells you what to fix, helps you fix it, and
          measures what changes. Built for businesses in Nigeria, with or without a website.
        </p>
        <div className="row" style={{ flexWrap: 'wrap' }}>
          <Link className="btn primary" to="/register">Check my business for free</Link>
          <Link className="btn" to="/login">Sign in</Link>
        </div>
      </section>

      <section className="landing-grid">
        {features.map(([t, d]) => (
          <div key={t} className="card"><h3>{t}</h3><p className="muted" style={{ margin: 0 }}>{d}</p></div>
        ))}
      </section>

      <section className="card stack landing-promise">
        <h2>Our promise</h2>
        <ul>
          <li>No guaranteed rankings. Nobody can honestly promise them, so we show what we measure and say when something is not measured yet.</li>
          <li>You approve everything. Nothing is published to your Google profile, website or pages without an owner or admin saying yes.</li>
          <li>We never ask for your passwords. You connect Google and other accounts on their own sign-in pages, and you can disconnect any time.</li>
        </ul>
      </section>

      <section className="stack">
        <h2>Plans</h2>
        <div className="landing-grid">
          {plans.map(([n, p, d]) => (
            <div key={n} className="card"><h3>{n}</h3><div style={{ fontWeight: 700, fontSize: '1.15rem' }}>{p}</div><p className="muted small" style={{ margin: 0 }}>{d}</p></div>
          ))}
        </div>
        <p className="small muted">Paid in naira through Paystack. Cancel any time.</p>
      </section>

      <footer className="landing-footer small muted">
        <span>© 2026 Findriva, by ERA Technologiz, Lagos</span>
        <a href="/privacy/">Privacy policy</a>
        <a href="/terms/">Terms of service</a>
      </footer>
    </div>
  )
}
