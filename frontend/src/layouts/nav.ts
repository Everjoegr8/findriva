import {
  BarChart3, Blocks, Bot, Briefcase, Building2, CreditCard, Gauge, Layers, PlugZap, Settings2, Globe, LayoutDashboard, ListChecks, MapPin,
  Network, PenLine, Plus, Radar, Search, ShieldCheck, Sparkles, Store, TrendingUp, Users, Wrench, AlertTriangle, ScrollText,
  type LucideIcon,
} from 'lucide-react'

export interface NavItem { label: string; path: string; icon: LucideIcon; end?: boolean; keywords?: string; module?: string }
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
    { label: 'Keywords', path: 'keywords', icon: Search, keywords: 'search terms', module: 'keywords' },
    { label: 'Rankings & competitors', path: 'rankings', icon: BarChart3, keywords: 'positions serp competitors', module: 'rankings' },
    { label: 'Map grid', path: 'map-grid', icon: Radar, keywords: 'local pack maps geogrid', module: 'map_grid' },
    { label: 'AI answers', path: 'ai-answers', icon: Sparkles, keywords: 'chatgpt gemini perplexity ai visibility', module: 'ai_answers' },
  ] },
  { title: 'Presence', items: [
    { label: 'Google profile & reviews', path: 'google-profile', icon: MapPin, keywords: 'gbp maps reviews', module: 'google_profile' },
    { label: 'Profiles & listings', path: 'assets', icon: Network, keywords: 'instagram facebook tiktok whatsapp directories' },
    { label: 'Public page', path: 'public-page', icon: Globe, keywords: 'landing page site', module: 'public_pages' },
    { label: 'Website connection', path: 'website', icon: Store, keywords: 'wordpress plugin connect', module: 'wordpress' },
  ] },
  { title: 'Content', items: [
    { label: 'Content Studio', path: 'content', icon: PenLine, keywords: 'posts drafts writing', module: 'content_studio' },
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
  { title: 'Configuration', items: [
    { label: 'Plans & limits', path: '/control/plans', icon: Layers, keywords: 'pricing entitlements price limits' },
    { label: 'Modules', path: '/control/modules', icon: Blocks, keywords: 'features flags switch off kill' },
    { label: 'Providers', path: '/control/providers', icon: PlugZap, keywords: 'paystack google bing dataforseo anthropic email keys health' },
    { label: 'AI models', path: '/control/ai', icon: Bot, keywords: 'claude model ai answers content' },
    { label: 'Settings', path: '/control/settings', icon: Settings2, keywords: 'signups announcement banner' },
  ] },
  { title: 'Operations', items: [
    { label: 'Failed jobs', path: '/control/jobs', icon: AlertTriangle, keywords: 'queue errors retry' },
    { label: 'Activity log', path: '/control/activity', icon: ScrollText, keywords: 'audit log history' },
  ] },
]
