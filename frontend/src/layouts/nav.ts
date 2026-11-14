import {
  BarChart3, Briefcase, Building2, CreditCard, Gauge, Globe, LayoutDashboard, ListChecks, MapPin,
  Network, PenLine, Plus, Radar, Search, ShieldCheck, Sparkles, Store, TrendingUp, Users, Wrench, AlertTriangle, ScrollText,
  type LucideIcon,
} from 'lucide-react'

export interface NavItem { label: string; path: string; icon: LucideIcon; end?: boolean; keywords?: string }
export interface NavGroup { title: string; items: NavItem[] }

/** Sections of one business, grouped by the job they do. Paths are relative to /businesses/:id. */
export const BUSINESS_NAV: NavGroup[] = [
  { title: 'Overview', items: [
    { label: 'Overview', path: '', icon: LayoutDashboard, end: true, keywords: 'home summary dashboard' },
    { label: 'Results', path: 'results', icon: TrendingUp, keywords: 'report progress clicks impressions' },
  ] },
  { title: 'Improve', items: [
    { label: 'Action Centre', path: 'actions', icon: ListChecks, keywords: 'tasks todo approvals' },
    { label: 'Discoverability audit', path: 'audit', icon: Gauge, keywords: 'website issues crawl seo health' },
    { label: 'Ready-made fixes', path: 'fixes', icon: Wrench, keywords: 'schema code snippets' },
  ] },
  { title: 'Search visibility', items: [
    { label: 'Keywords', path: 'keywords', icon: Search, keywords: 'search terms' },
    { label: 'Rankings & competitors', path: 'rankings', icon: BarChart3, keywords: 'positions serp competitors' },
    { label: 'Map grid', path: 'map-grid', icon: Radar, keywords: 'local pack maps geogrid' },
    { label: 'AI answers', path: 'ai-answers', icon: Sparkles, keywords: 'chatgpt gemini perplexity ai visibility' },
  ] },
  { title: 'Presence', items: [
    { label: 'Google profile & reviews', path: 'google-profile', icon: MapPin, keywords: 'gbp maps reviews' },
    { label: 'Profiles & listings', path: 'assets', icon: Network, keywords: 'instagram facebook tiktok whatsapp directories' },
    { label: 'Public page', path: 'public-page', icon: Globe, keywords: 'landing page site' },
    { label: 'Website connection', path: 'website', icon: Store, keywords: 'wordpress plugin connect' },
  ] },
  { title: 'Content', items: [
    { label: 'Content Studio', path: 'content', icon: PenLine, keywords: 'posts drafts writing' },
    { label: 'Business profile', path: 'profile', icon: Briefcase, keywords: 'details hours address phone' },
  ] },
]

export const WORKSPACE_NAV: NavItem[] = [
  { label: 'Businesses', path: '/', icon: Building2, end: true, keywords: 'home all' },
  { label: 'Add a business', path: '/businesses/new', icon: Plus, keywords: 'new create onboarding' },
  { label: 'Plan and billing', path: '/billing', icon: CreditCard, keywords: 'subscription payment upgrade invoice' },
  { label: 'Account security', path: '/account', icon: ShieldCheck, keywords: 'password two-factor 2fa sessions' },
]

export const CONTROL_NAV: NavGroup[] = [
  { title: 'Platform', items: [
    { label: 'Overview', path: '/control', icon: LayoutDashboard, end: true, keywords: 'stats revenue health' },
  ] },
  { title: 'Customers', items: [
    { label: 'People', path: '/control/users', icon: Users, keywords: 'users accounts' },
    { label: 'Workspaces', path: '/control/workspaces', icon: Building2, keywords: 'tenants businesses' },
  ] },
  { title: 'Revenue', items: [
    { label: 'Payments', path: '/control/payments', icon: CreditCard, keywords: 'paystack billing mrr' },
  ] },
  { title: 'Operations', items: [
    { label: 'Failed jobs', path: '/control/jobs', icon: AlertTriangle, keywords: 'queue errors retry' },
    { label: 'Activity log', path: '/control/activity', icon: ScrollText, keywords: 'audit log history' },
  ] },
]
