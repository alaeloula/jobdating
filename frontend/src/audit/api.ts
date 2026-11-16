import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { apiRequest } from '../lib/api'

export interface AuditLogEntry {
  id: number
  created_at: string
  actor: { id: number | null; label: string | null } | null
  action: string
  action_label: string
  category: string
  category_label: string
  subject: { type: string; id: number | null; label: string | null } | null
  old_values: Record<string, unknown> | null
  new_values: Record<string, unknown> | null
  ip: string | null
}

export interface AuditLogPage {
  data: AuditLogEntry[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

export interface AuditFilters {
  category?: string
  user_id?: string
  from?: string
  to?: string
  page: number
}

export interface AuditFilterOptions {
  authors: { id: number; label: string }[]
  categories: { value: string; label: string }[]
}

export const PER_PAGE = 50

export function auditQueryString(filters: AuditFilters): string {
  const params = new URLSearchParams()
  if (filters.category) params.set('category', filters.category)
  if (filters.user_id) params.set('user_id', filters.user_id)
  if (filters.from) params.set('from', filters.from)
  if (filters.to) params.set('to', filters.to)
  params.set('page', String(filters.page))
  params.set('per_page', String(PER_PAGE))
  return params.toString()
}

export function useAuditLogs(filters: AuditFilters) {
  return useQuery({
    queryKey: ['audit-logs', filters],
    queryFn: () => apiRequest<AuditLogPage>(`/audit-logs?${auditQueryString(filters)}`),
    placeholderData: keepPreviousData,
  })
}

export function useAuditFilterOptions() {
  return useQuery({
    queryKey: ['audit-logs', 'filters'],
    queryFn: async () => (await apiRequest<{ data: AuditFilterOptions }>('/audit-logs/filters')).data,
    staleTime: 60_000,
  })
}
