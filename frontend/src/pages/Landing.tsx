import { useEffect, useRef, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { AnimatePresence, MotionConfig, animate, motion, useInView, useScroll, useTransform } from 'motion/react'
import '@fontsource-variable/plus-jakarta-sans'
import '@fontsource-variable/inter'
import '../landing.css'
import { photos, src, srcSet, type Photo } from './landing/photos'

const ease = [0.22, 1, 0.36, 1] as const

function Img({ photo, w, h, className = '', eager = false }: { photo: Photo; w: number; h: number; className?: string; eager?: boolean }) {
  const [failed, setFailed] = useState(false)
  return (
    <div className={`photo relative overflow-hidden ${className}`}>
      {!failed && (
        <img
          src={src(photo, w, h)} srcSet={srcSet(photo, w, h)} alt={photo.alt} width={w} height={h}
          loading={eager ? 'eager' : 'lazy'} decoding="async" onError={() => setFailed(true)}
          className="h-full w-full object-cover"
        />
      )}
    </div>
  )
}

function Reveal({ children, delay = 0, className = '', y = 28 }: { children: ReactNode; delay?: number; className?: string; y?: number }) {
  return (
    <motion.div className={className} initial={{ opacity: 0, y }} whileInView={{ opacity: 1, y: 0 }} viewport={{ once: true, margin: '-60px' }} transition={{ duration: 0.7, delay, ease }}>
      {children}
    </motion.div>
  )
}

function CountUp({ to, suffix = '' }: { to: number; suffix?: string }) {
  const ref = useRef<HTMLSpanElement>(null)
  const inView = useInView(ref, { once: true })
  useEffect(() => {
    if (!inView || !ref.current) return
    const node = ref.current
    const c = animate(0, to, { duration: 1.6, ease, onUpdate: (v) => { node.textContent = Math.round(v).toLocaleString() + suffix } })
    return () => c.stop()
  }, [inView, to, suffix])
  return <span ref={ref}>0{suffix}</span>
}

const channels = ['Google Search', 'Google Maps', 'Google Business Profile', 'Bing', 'Instagram', 'Facebook', 'WhatsApp Business', 'TikTok', 'AI assistants', 'Your website', 'WordPress', 'Business directories']
const rotating = ['Google', 'Google Maps', 'Instagram', 'AI assistants']

const icon = {
  search: 'M11 4a7 7 0 1 0 4.4 12.4l4.1 4.1 1.4-1.4-4.1-4.1A7 7 0 0 0 11 4Zm0 2a5 5 0 1 1 0 10 5 5 0 0 1 0-10Z',
  wrench: 'M14.7 3a5 5 0 0 0-4.6 6.9L3 17v4h4l7.1-7.1A5 5 0 0 0 21 9.3l-3 3-2.3-.3-.3-2.3 3-3A5 5 0 0 0 14.7 3Z',
  pin: 'M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7Zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5Z',
  spark: 'M12 2l2.2 6.3L20 10l-5.8 1.7L12 18l-2.2-6.3L4 10l5.8-1.7L12 2Zm7 12l1 3 3 1-3 1-1 3-1-3-3-1 3-1 1-3Z',
  star: 'M12 2.5l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4l-5.9 3.1 1.2-6.5L2.5 9.4l6.6-.9L12 2.5Z',
  chart: 'M4 20V10h3v10H4Zm6.5 0V4h3v16h-3ZM17 20v-7h3v7h-3Z',
  check: 'M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2Z',
  shield: 'M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3Zm-1.2 14.2-3.5-3.5 1.4-1.4 2.1 2.1 4.6-4.6 1.4 1.4-6 6Z',
  lock: 'M17 9h-1V7a4 4 0 1 0-8 0v2H7a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2Zm-7-2a2 2 0 1 1 4 0v2h-4V7Z',
}
function Icon({ d, className = 'h-6 w-6' }: { d: string; className?: string }) {
  return <svg viewBox="0 0 24 24" aria-hidden="true" className={className} fill="currentColor"><path d={d} /></svg>
}

const features = [
  { i: icon.search, t: 'Find what is holding you back', d: 'One check of your website, Google profile, social pages and listings, with a clear list of what to fix first.' },
  { i: icon.wrench, t: 'Fix it with ready-made help', d: 'Search-engine files, business details for Google, short pages and posts, written only from facts you approve.' },
  { i: icon.pin, t: 'See your spot on Google Maps', d: 'Check where you appear on the map from every corner of your area, and which competitors show up instead.' },
  { i: icon.spark, t: 'Know what AI assistants say', d: 'We ask AI assistants the questions your customers ask, and show whether they mention you.' },
  { i: icon.star, t: 'Answer reviews with care', d: 'All your Google reviews in one inbox, with suggested replies that an owner approves before anything is posted.' },
  { i: icon.chart, t: 'Measure what changed', d: 'Clicks, searches, calls and direction requests from the accounts you connect, in a clear monthly report.' },
]

const audiences = [
  { p: photos.streetFood, t: 'Food vendors and restaurants', d: 'Show up when people nearby search for food, with the right hours and directions.' },
  { p: photos.boutique, t: 'Shops and boutiques', d: 'Turn Instagram followers into walk-in customers who can find your store.' },
  { p: photos.shop, t: 'Local shops and services', d: 'Be the first name people see on Google and Maps in your area.' },
  { p: photos.tomatoes, t: 'Market traders', d: 'No website needed. Start with a free Findriva page and your Google profile.' },
]

const steps = [
  { t: 'Add your business', d: 'Your name, what you sell, where you are and how to reach you. A website is optional.' },
  { t: 'Get your check-up', d: 'We look at how you appear on Google, Maps, social media and AI assistants, and list what to fix first.' },
  { t: 'Approve the fixes', d: 'We prepare the fixes. You read them and say yes. Nothing is published without your approval.' },
  { t: 'Watch what changes', d: 'Connect Google and Bing to see real clicks, calls and searches, and get a report every month.' },
]

const plans = [
  { n: 'Free snapshot', p: '₦0', per: '', d: 'One business: audits, your action list and ready-made fixes.', f: ['Discoverability audit', 'Action list', 'Ready-made fixes', 'Free business page'] },
  { n: 'Starter', p: '₦9,900', per: '/ month', d: 'One business, with keyword data and regular checks.', f: ['Everything in Free', 'Keyword research', 'Weekly rankings', 'Map and AI answer checks'] },
  { n: 'Growth', p: '₦29,900', per: '/ month', d: 'Up to 5 businesses, with higher limits on every check.', f: ['Everything in Starter', 'Up to 5 businesses', 'Bigger map grids', 'Review replies and reports'], hot: true },
  { n: 'Agency', p: '₦99,000', per: '/ month', d: 'Up to 50 client businesses for agencies and teams.', f: ['Everything in Growth', 'Up to 50 businesses', 'Team roles and approvals', 'Client reports'] },
]

const faqs = [
  ['Do I need a website?', 'No. Many of our customers sell on Instagram or WhatsApp. Findriva can give you a free business page and help you set up your Google profile.'],
  ['Can you guarantee I will rank first on Google?', 'No, and nobody honestly can. Google decides its own results. We show what we actually measure, and we say clearly when something has not been measured yet.'],
  ['Will you post anything without asking me?', 'Never. Every reply, profile change or website update waits for an owner or admin in your workspace to approve it.'],
  ['Do you need my passwords?', 'No. You connect Google, Bing and other accounts on their own sign-in pages, and you can disconnect at any time.'],
  ['How do I pay?', 'In naira, through Paystack, with your card or bank. Plans renew monthly and you can cancel any time.'],
]

function Nav() {
  const [open, setOpen] = useState(false)
  const [scrolled, setScrolled] = useState(false)
  useEffect(() => {
    const on = () => setScrolled(window.scrollY > 24)
    on()
    window.addEventListener('scroll', on, { passive: true })
    return () => window.removeEventListener('scroll', on)
  }, [])
  const links = [['#how', 'How it works'], ['#features', 'Features'], ['#pricing', 'Pricing'], ['#faq', 'FAQ']]
  return (
    <header className={`fixed inset-x-0 top-0 z-50 transition-all duration-300 ${scrolled || open ? 'bg-ink/85 shadow-lg shadow-black/10 backdrop-blur-xl' : 'bg-transparent'}`}>
      <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
        <Link to="/" className="flex items-center gap-2 text-lg font-bold text-white" style={{ fontFamily: 'var(--font-display)' }}>
          <img src="/favicon.svg" alt="" className="h-8 w-8" /> Findriva
        </Link>
        <nav className="hidden items-center gap-8 text-sm font-medium text-white/80 md:flex" aria-label="Main">
          {links.map(([h, l]) => <a key={h} href={h} className="transition-colors hover:text-white">{l}</a>)}
        </nav>
        <div className="hidden items-center gap-3 md:flex">
          <Link to="/login" className="rounded-full px-4 py-2 text-sm font-semibold text-white/90 transition hover:bg-white/10">Sign in</Link>
          <Link to="/register" className="rounded-full bg-lime px-5 py-2.5 text-sm font-bold text-ink shadow-lg shadow-lime/20 transition hover:-translate-y-0.5 hover:bg-white">Get started free</Link>
        </div>
        <button type="button" className="rounded-lg border-0 bg-transparent p-2 text-white md:hidden" aria-expanded={open} aria-label={open ? 'Close menu' : 'Open menu'} onClick={() => setOpen(!open)}>
          <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
            {open ? <path d="M6 6l12 12M18 6 6 18" /> : <path d="M4 7h16M4 12h16M4 17h16" />}
          </svg>
        </button>
      </div>
      <AnimatePresence>
        {open && (
          <motion.nav initial={{ height: 0, opacity: 0 }} animate={{ height: 'auto', opacity: 1 }} exit={{ height: 0, opacity: 0 }} transition={{ duration: 0.3, ease }} className="overflow-hidden md:hidden" aria-label="Mobile">
            <div className="flex flex-col gap-1 px-4 pb-5">
              {links.map(([h, l]) => <a key={h} href={h} onClick={() => setOpen(false)} className="rounded-lg px-3 py-3 text-base font-medium text-white/90 hover:bg-white/10">{l}</a>)}
              <div className="mt-2 grid grid-cols-2 gap-3">
                <Link to="/login" className="rounded-full border border-white/20 px-4 py-3 text-center text-sm font-semibold text-white">Sign in</Link>
                <Link to="/register" className="rounded-full bg-lime px-4 py-3 text-center text-sm font-bold text-ink">Get started</Link>
              </div>
            </div>
          </motion.nav>
        )}
      </AnimatePresence>
    </header>
  )
}

function RotatingWord() {
  const [i, setI] = useState(0)
  useEffect(() => {
    const t = setInterval(() => setI((x) => (x + 1) % rotating.length), 2400)
    return () => clearInterval(t)
  }, [])
  return (
    <span className="relative inline-grid align-bottom">
      <AnimatePresence mode="wait">
        <motion.span key={rotating[i]} className="grad-text col-start-1 row-start-1 whitespace-nowrap" initial={{ y: '60%', opacity: 0, filter: 'blur(6px)' }} animate={{ y: 0, opacity: 1, filter: 'blur(0px)' }} exit={{ y: '-60%', opacity: 0, filter: 'blur(6px)' }} transition={{ duration: 0.5, ease }}>
          {rotating[i]}
        </motion.span>
      </AnimatePresence>
    </span>
  )
}

function FloatCard({ children, className = '', delay = 0 }: { children: ReactNode; className?: string; delay?: number }) {
  return (
    <motion.div initial={{ opacity: 0, scale: 0.85, y: 20 }} animate={{ opacity: 1, scale: 1, y: 0 }} transition={{ duration: 0.8, delay, ease }} className={`absolute z-10 ${className}`}>
      <div className="animate-float rounded-2xl border border-white/60 bg-white/95 p-3 text-xs text-slate-800 shadow-2xl shadow-black/25 backdrop-blur sm:p-4 sm:text-sm" style={{ animationDelay: `${delay}s` }}>
        {children}
      </div>
    </motion.div>
  )
}

function Hero() {
  const ref = useRef<HTMLElement>(null)
  const { scrollYProgress } = useScroll({ target: ref, offset: ['start start', 'end start'] })
  const y = useTransform(scrollYProgress, [0, 1], [0, 120])
  return (
    <section ref={ref} className="grain relative isolate overflow-hidden bg-ink pb-20 pt-28 text-white sm:pt-32 lg:pb-28 lg:pt-40">
      <div aria-hidden="true" className="pointer-events-none absolute inset-0 -z-10">
        <div className="animate-blob absolute -left-32 -top-24 h-[28rem] w-[28rem] rounded-full bg-teal-500/30 blur-3xl" />
        <div className="animate-blob absolute -right-24 top-40 h-[26rem] w-[26rem] rounded-full bg-lime/20 blur-3xl" style={{ animationDelay: '-6s' }} />
        <div className="animate-blob absolute bottom-0 left-1/3 h-[22rem] w-[22rem] rounded-full bg-sun/15 blur-3xl" style={{ animationDelay: '-12s' }} />
      </div>
      <div className="mx-auto grid grid-cols-1 max-w-7xl items-center gap-14 px-4 sm:px-6 lg:grid-cols-2 lg:gap-10 lg:px-8">
        <div>
          <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.6, ease }} className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-medium text-white/85 backdrop-blur sm:text-sm">
            <span className="relative flex h-2 w-2"><span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-lime opacity-75" /><span className="relative inline-flex h-2 w-2 rounded-full bg-lime" /></span>
            Built in Lagos for Nigerian businesses
          </motion.div>
          <motion.h1 initial={{ opacity: 0, y: 24 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.8, delay: 0.1, ease }} className="mt-6 text-4xl font-extrabold leading-[1.08] tracking-tight sm:text-5xl lg:text-6xl xl:text-7xl">
            Get found on <br className="hidden sm:block" /><RotatingWord /><br />by the customers already looking.
          </motion.h1>
          <motion.p initial={{ opacity: 0, y: 24 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.8, delay: 0.25, ease }} className="mt-6 max-w-xl text-base leading-relaxed text-white/75 sm:text-lg">
            Findriva checks how your business shows up online, tells you exactly what to fix, prepares the fixes for you to approve, and measures what changes. With or without a website.
          </motion.p>
          <motion.div initial={{ opacity: 0, y: 24 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.8, delay: 0.4, ease }} className="mt-8 flex flex-col gap-3 sm:flex-row">
            <Link to="/register" className="group inline-flex items-center justify-center gap-2 rounded-full bg-lime px-7 py-4 text-base font-bold text-ink shadow-xl shadow-lime/25 transition hover:-translate-y-0.5 hover:bg-white">
              Check my business free
              <svg viewBox="0 0 24 24" className="h-5 w-5 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
            </Link>
            <a href="#how" className="inline-flex items-center justify-center rounded-full border border-white/20 px-7 py-4 text-base font-semibold text-white transition hover:bg-white/10">See how it works</a>
          </motion.div>
          <motion.ul initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ duration: 0.8, delay: 0.6 }} className="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-sm text-white/70">
            {['Free to start', 'No passwords needed', 'You approve everything'].map((t) => (
              <li key={t} className="flex items-center gap-2"><Icon d={icon.check} className="h-4 w-4 text-lime" />{t}</li>
            ))}
          </motion.ul>
        </div>

        <motion.div style={{ y }} className="relative mx-auto w-full max-w-lg lg:max-w-none">
          <div className="grid grid-cols-5 grid-rows-6 gap-3 sm:gap-4" style={{ height: 'min(560px, 120vw)' }}>
            <motion.div initial={{ opacity: 0, x: 40 }} animate={{ opacity: 1, x: 0 }} transition={{ duration: 0.9, delay: 0.2, ease }} className="col-span-3 row-span-4">
              <Img photo={photos.boutique} w={520} h={640} eager className="h-full rounded-3xl ring-1 ring-white/10" />
            </motion.div>
            <motion.div initial={{ opacity: 0, y: -40 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.9, delay: 0.35, ease }} className="col-span-2 row-span-3">
              <Img photo={photos.tailor} w={360} h={480} eager className="h-full rounded-3xl ring-1 ring-white/10" />
            </motion.div>
            <motion.div initial={{ opacity: 0, y: 40 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.9, delay: 0.5, ease }} className="col-span-2 row-span-3">
              <Img photo={photos.streetFood} w={360} h={480} eager className="h-full rounded-3xl ring-1 ring-white/10" />
            </motion.div>
            <motion.div initial={{ opacity: 0, x: -40 }} animate={{ opacity: 1, x: 0 }} transition={{ duration: 0.9, delay: 0.65, ease }} className="col-span-3 row-span-2">
              <Img photo={photos.market} w={520} h={300} eager className="h-full rounded-3xl ring-1 ring-white/10" />
            </motion.div>
          </div>

          <FloatCard className="-left-2 -top-6 sm:-left-10 sm:-top-8" delay={0.9}>
            <div className="flex items-center gap-3">
              <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-teal-100 text-teal-700"><Icon d={icon.pin} className="h-5 w-5" /></div>
              <div><div className="font-semibold">Seen on Google Maps</div><div className="text-slate-500">“tailor near Yaba”</div></div>
            </div>
          </FloatCard>
          <FloatCard className="-right-2 top-1/2 sm:-right-6" delay={1.1}>
            <div className="mb-1 flex items-center gap-1 text-sun">{[0, 1, 2, 3, 4].map((k) => <Icon key={k} d={icon.star} className="h-4 w-4" />)}</div>
            <div className="font-semibold">Reply ready to approve</div>
            <div className="text-slate-500">“Thank you, Chioma! See you soon.”</div>
          </FloatCard>
          <FloatCard className="bottom-16 left-4 sm:bottom-10 sm:left-10" delay={1.3}>
            <div className="text-slate-500">Fixes to do</div>
            <div className="mt-1 flex items-center gap-2">
              <div className="h-2 w-28 overflow-hidden rounded-full bg-slate-200 sm:w-36"><motion.div className="h-full rounded-full bg-gradient-to-r from-teal-500 to-lime" initial={{ width: '15%' }} animate={{ width: '72%' }} transition={{ duration: 2, delay: 1.6, ease }} /></div>
              <span className="font-bold text-teal-700">8 of 11 done</span>
            </div>
          </FloatCard>
          <p className="mt-10 text-center text-xs text-white/45">Example screens. Your results depend on your business and what you approve.</p>
        </motion.div>
      </div>
    </section>
  )
}

function Marquee() {
  return (
    <section aria-label="Where we check your business" className="border-y border-black/5 bg-white py-6">
      <p className="mb-4 px-4 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">One check across the places customers look</p>
      <div className="relative overflow-hidden [mask-image:linear-gradient(to_right,transparent,black_10%,black_90%,transparent)]">
        <div className="animate-marquee flex w-max gap-4">
          {[...channels, ...channels].map((c, k) => (
            <span key={k} aria-hidden={k >= channels.length} className="whitespace-nowrap rounded-full border border-slate-200 bg-slate-50 px-5 py-2.5 text-sm font-semibold text-slate-700">{c}</span>
          ))}
        </div>
      </div>
    </section>
  )
}

function SectionHead({ kicker, title, sub, light = false }: { kicker: string; title: ReactNode; sub?: string; light?: boolean }) {
  return (
    <Reveal className="mx-auto max-w-3xl text-center">
      <div className={`text-sm font-bold uppercase tracking-[0.2em] ${light ? 'text-lime' : 'text-teal-700'}`}>{kicker}</div>
      <h2 className={`mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl lg:text-5xl ${light ? 'text-white' : 'text-ink'}`}>{title}</h2>
      {sub && <p className={`mt-4 text-base sm:text-lg ${light ? 'text-white/70' : 'text-slate-600'}`}>{sub}</p>}
    </Reveal>
  )
}

function Audiences() {
  return (
    <section className="py-20 sm:py-28">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <SectionHead kicker="Made for you" title="For businesses people search for every day" sub="From a buka in Surulere to a boutique in Lekki, your customers search before they buy. Findriva makes sure they find you." />
        <div className="mt-14 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {audiences.map((a, k) => (
            <Reveal key={a.t} delay={k * 0.1}>
              <article className="group relative h-96 overflow-hidden rounded-3xl shadow-lg shadow-black/5">
                <Img photo={a.p} w={400} h={520} className="absolute inset-0 h-full [&_img]:transition-transform [&_img]:duration-700 group-hover:[&_img]:scale-110" />
                <div className="absolute inset-0 bg-gradient-to-t from-ink via-ink/40 to-transparent" />
                <div className="absolute inset-x-0 bottom-0 p-6 text-white">
                  <h3 className="text-xl font-bold">{a.t}</h3>
                  <p className="mt-2 text-sm text-white/80 transition-all duration-500 sm:max-h-0 sm:opacity-0 sm:group-hover:max-h-24 sm:group-hover:opacity-100">{a.d}</p>
                </div>
              </article>
            </Reveal>
          ))}
        </div>
      </div>
    </section>
  )
}

function HowItWorks() {
  const ref = useRef<HTMLDivElement>(null)
  const { scrollYProgress } = useScroll({ target: ref, offset: ['start 75%', 'end 60%'] })
  const scaleY = useTransform(scrollYProgress, [0, 1], [0, 1])
  return (
    <section id="how" className="scroll-mt-20 bg-white py-20 sm:py-28">
      <div className="mx-auto grid grid-cols-1 max-w-7xl gap-14 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
        <div className="lg:sticky lg:top-28 lg:self-start">
          <SectionHead kicker="How it works" title="From invisible to easy to find, in four steps" />
          <Reveal delay={0.2} className="mt-10 hidden lg:block">
            <Img photo={photos.shop} w={620} h={420} className="h-80 rounded-3xl shadow-2xl shadow-black/10" />
          </Reveal>
        </div>
        <div ref={ref} className="relative pl-12 sm:pl-16">
          <div className="absolute bottom-4 left-[19px] top-4 w-0.5 bg-slate-200 sm:left-[23px]" />
          <motion.div style={{ scaleY }} className="absolute bottom-4 left-[19px] top-4 w-0.5 origin-top bg-gradient-to-b from-teal-500 to-lime sm:left-[23px]" />
          <ol className="flex flex-col gap-12">
            {steps.map((s, k) => (
              <Reveal key={s.t} delay={0.05} className="relative">
                <li className="list-none">
                  <div className="absolute -left-12 flex h-10 w-10 items-center justify-center rounded-full bg-ink text-sm font-bold text-lime ring-4 ring-white sm:-left-16 sm:h-12 sm:w-12 sm:text-base">{k + 1}</div>
                  <h3 className="text-xl font-bold text-ink sm:text-2xl">{s.t}</h3>
                  <p className="mt-2 text-base leading-relaxed text-slate-600">{s.d}</p>
                </li>
              </Reveal>
            ))}
          </ol>
        </div>
      </div>
    </section>
  )
}

function Features() {
  return (
    <section id="features" className="scroll-mt-20 py-20 sm:py-28">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <SectionHead kicker="Everything in one place" title="All the tools to be found, trusted and contacted" />
        <div className="mt-14 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {features.map((f, k) => (
            <Reveal key={f.t} delay={(k % 3) * 0.1}>
              <motion.div whileHover={{ y: -6 }} transition={{ type: 'spring', stiffness: 300, damping: 20 }} className="group h-full rounded-3xl border border-slate-200 bg-white p-7 shadow-sm transition-shadow hover:shadow-xl hover:shadow-teal-900/5">
                <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-500 to-teal-700 text-white shadow-lg shadow-teal-600/25 transition-transform duration-300 group-hover:rotate-6 group-hover:scale-110">
                  <Icon d={f.i} />
                </div>
                <h3 className="mt-5 text-lg font-bold text-ink">{f.t}</h3>
                <p className="mt-2 text-sm leading-relaxed text-slate-600">{f.d}</p>
              </motion.div>
            </Reveal>
          ))}
        </div>
      </div>
    </section>
  )
}

function Promise() {
  const ref = useRef<HTMLElement>(null)
  const { scrollYProgress } = useScroll({ target: ref, offset: ['start end', 'end start'] })
  const y = useTransform(scrollYProgress, [0, 1], ['-12%', '12%'])
  const items = [
    { i: icon.shield, n: 0, s: '', t: 'guaranteed rankings', d: 'Nobody can honestly promise them. We show what we measure and say when something is not measured yet.' },
    { i: icon.check, n: 100, s: '%', t: 'approved by you', d: 'Nothing reaches your Google profile, website or pages until an owner or admin says yes.' },
    { i: icon.lock, n: 0, s: '', t: 'passwords asked for', d: 'You connect accounts on their own sign-in pages, and you can disconnect at any time.' },
  ]
  return (
    <section ref={ref} className="relative isolate overflow-hidden bg-ink py-24 text-white sm:py-32">
      <motion.div style={{ y }} className="absolute -inset-y-[15%] inset-x-0 -z-10">
        <Img photo={photos.market} w={1600} h={900} className="h-full opacity-30" />
      </motion.div>
      <div className="absolute inset-0 -z-10 bg-gradient-to-b from-ink via-ink/80 to-ink" />
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <SectionHead light kicker="Our promise" title="Honest help, with you in control" />
        <div className="mt-14 grid grid-cols-1 gap-6 md:grid-cols-3">
          {items.map((it, k) => (
            <Reveal key={it.t} delay={k * 0.15}>
              <div className="h-full rounded-3xl border border-white/10 bg-white/5 p-8 backdrop-blur-md">
                <Icon d={it.i} className="h-8 w-8 text-lime" />
                <div className="mt-5 text-5xl font-extrabold tracking-tight" style={{ fontFamily: 'var(--font-display)' }}><CountUp to={it.n} suffix={it.s} /></div>
                <div className="mt-1 text-lg font-semibold text-white">{it.t}</div>
                <p className="mt-3 text-sm leading-relaxed text-white/70">{it.d}</p>
              </div>
            </Reveal>
          ))}
        </div>
      </div>
    </section>
  )
}

function Pricing() {
  return (
    <section id="pricing" className="scroll-mt-20 py-20 sm:py-28">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <SectionHead kicker="Pricing" title="Start free. Grow when you are ready." sub="Paid in naira through Paystack. Cancel any time." />
        <div className="mt-14 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {plans.map((p, k) => (
            <Reveal key={p.n} delay={k * 0.08} className="h-full">
              <motion.div whileHover={{ y: -8 }} transition={{ type: 'spring', stiffness: 300, damping: 22 }}
                className={`relative flex h-full flex-col rounded-3xl p-7 ${p.hot ? 'bg-ink text-white shadow-2xl shadow-teal-900/30 ring-2 ring-lime' : 'border border-slate-200 bg-white text-ink shadow-sm'}`}>
                {p.hot && <span className="absolute -top-3 left-7 rounded-full bg-lime px-3 py-1 text-xs font-bold text-ink">Most popular</span>}
                <h3 className="text-lg font-bold">{p.n}</h3>
                <div className="mt-4 flex items-baseline gap-1"><span className="text-4xl font-extrabold tracking-tight" style={{ fontFamily: 'var(--font-display)' }}>{p.p}</span><span className={p.hot ? 'text-white/60' : 'text-slate-500'}>{p.per}</span></div>
                <p className={`mt-3 text-sm ${p.hot ? 'text-white/70' : 'text-slate-600'}`}>{p.d}</p>
                <ul className="mt-6 flex flex-1 flex-col gap-3 text-sm">
                  {p.f.map((f) => <li key={f} className="flex items-start gap-2"><Icon d={icon.check} className={`mt-0.5 h-4 w-4 flex-none ${p.hot ? 'text-lime' : 'text-teal-600'}`} />{f}</li>)}
                </ul>
                <Link to="/register" className={`mt-8 rounded-full px-5 py-3 text-center text-sm font-bold transition hover:-translate-y-0.5 ${p.hot ? 'bg-lime text-ink hover:bg-white' : 'bg-ink text-white hover:bg-teal-800'}`}>
                  {p.p === '₦0' ? 'Start free' : `Choose ${p.n}`}
                </Link>
              </motion.div>
            </Reveal>
          ))}
        </div>
      </div>
    </section>
  )
}

function Faq() {
  const [open, setOpen] = useState<number | null>(0)
  return (
    <section id="faq" className="scroll-mt-20 bg-white py-20 sm:py-28">
      <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
        <SectionHead kicker="Questions" title="Good questions, straight answers" />
        <div className="mt-12 flex flex-col gap-3">
          {faqs.map(([q, a], k) => (
            <Reveal key={q} delay={k * 0.05}>
              <div className={`rounded-2xl border transition-colors ${open === k ? 'border-teal-600/30 bg-teal-50/60' : 'border-slate-200 bg-white'}`}>
                <button type="button" onClick={() => setOpen(open === k ? null : k)} aria-expanded={open === k} className="flex w-full border-0 bg-transparent items-center justify-between gap-4 px-6 py-5 text-left text-base font-semibold text-ink">
                  {q}
                  <motion.span animate={{ rotate: open === k ? 45 : 0 }} className="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-ink text-lime">
                    <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round"><path d="M12 5v14M5 12h14" /></svg>
                  </motion.span>
                </button>
                <AnimatePresence initial={false}>
                  {open === k && (
                    <motion.div initial={{ height: 0, opacity: 0 }} animate={{ height: 'auto', opacity: 1 }} exit={{ height: 0, opacity: 0 }} transition={{ duration: 0.35, ease }} className="overflow-hidden">
                      <p className="px-6 pb-6 text-slate-600">{a}</p>
                    </motion.div>
                  )}
                </AnimatePresence>
              </div>
            </Reveal>
          ))}
        </div>
      </div>
    </section>
  )
}

function FinalCta() {
  return (
    <section className="px-4 py-20 sm:px-6 sm:py-28 lg:px-8">
      <Reveal className="mx-auto max-w-7xl">
        <div className="relative isolate overflow-hidden rounded-[2rem] bg-ink px-6 py-16 text-center text-white sm:px-12 sm:py-24">
          <Img photo={photos.city} w={1400} h={700} className="absolute inset-0 -z-10 h-full" />
          <div className="absolute inset-0 -z-10 bg-gradient-to-t from-ink/85 via-ink/40 to-ink/15" />
          <h2 className="mx-auto max-w-3xl text-3xl font-extrabold tracking-tight drop-shadow-[0_2px_12px_rgba(0,0,0,0.6)] sm:text-5xl">Your customers are searching right now.</h2>
          <p className="mx-auto mt-5 max-w-xl text-base text-white/90 drop-shadow-[0_1px_8px_rgba(0,0,0,0.7)] sm:text-lg">See how your business shows up in a few minutes. It is free to start, and you do not need a website.</p>
          <motion.div whileHover={{ scale: 1.04 }} whileTap={{ scale: 0.97 }} className="mt-9 inline-block">
            <Link to="/register" className="inline-flex rounded-full bg-lime px-8 py-4 text-base font-bold text-ink shadow-xl shadow-lime/25">Check my business free</Link>
          </motion.div>
        </div>
      </Reveal>
    </section>
  )
}

function Footer() {
  const credits = Array.from(new Map(Object.values(photos).map((p) => [p.by, p])).values())
  return (
    <footer className="border-t border-slate-200 bg-white">
      <div className="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-10 text-sm text-slate-600 sm:px-6 md:flex-row md:items-center md:justify-between lg:px-8">
        <div className="flex items-center gap-2 font-semibold text-ink"><img src="/favicon.svg" alt="" className="h-6 w-6" /> Findriva <span className="font-normal text-slate-500">by ERA Technologiz, Lagos</span></div>
        <nav className="flex flex-wrap gap-x-6 gap-y-2" aria-label="Footer">
          <a href="/privacy/" className="hover:text-ink">Privacy policy</a>
          <a href="/terms/" className="hover:text-ink">Terms of service</a>
          <Link to="/login" className="hover:text-ink">Sign in</Link>
        </nav>
      </div>
      <p className="mx-auto max-w-7xl px-4 pb-8 text-xs text-slate-400 sm:px-6 lg:px-8">
        © 2026 Findriva. Photos from Unsplash by {credits.map((p, k) => <span key={p.by}>{k > 0 && (k === credits.length - 1 ? ' and ' : ', ')}<a href={p.page} target="_blank" rel="noreferrer noopener" className="underline-offset-2 hover:underline">{p.by}</a></span>)}.
      </p>
    </footer>
  )
}

export default function LandingPage() {
  useEffect(() => {
    const prev = document.title
    document.title = 'Findriva: get found by the customers already looking'
    return () => { document.title = prev }
  }, [])
  return (
    <MotionConfig reducedMotion="user">
      <div className="lp">
        <Nav />
        <main>
          <Hero />
          <Marquee />
          <Audiences />
          <HowItWorks />
          <Features />
          <Promise />
          <Pricing />
          <Faq />
          <FinalCta />
        </main>
        <Footer />
      </div>
    </MotionConfig>
  )
}
