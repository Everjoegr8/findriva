import { useQuery } from '@tanstack/react-query'
import { api } from './api'

export interface PlatformInfo { signups_open: boolean; announcement: string | null; modules: Record<string, boolean> }

/** Platform-wide switches set by Findriva staff (sign-ups, announcement, which modules are on). */
export function usePlatform() {
  return useQuery({ queryKey: ['platform'], queryFn: () => api<{ data: PlatformInfo }>('/platform').then((r) => r.data), staleTime: 5 * 60_000 })
}

export function moduleOn(p: PlatformInfo | undefined, module?: string) {
  return !module || !p || p.modules[module] !== false
}
