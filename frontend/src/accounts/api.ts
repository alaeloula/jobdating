import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiRequest } from '../lib/api'

export type StaffRole = 'administrateur' | 'cme' | 'direction'
export type AccountStatus = 'actif' | 'invitation' | 'desactive' | 'supprime'

export interface StaffAccount {
  id: number
  first_name: string
  last_name: string
  full_name: string
  email: string
  phone: string | null
  role: StaffRole
  role_label: string
  status: AccountStatus
  last_login_at: string | null
  is_self: boolean
}

export interface AccountFilters {
  search: string
  role: '' | StaffRole
  status: '' | AccountStatus
  page: number
}

interface Paginated {
  data: StaffAccount[]
  meta: { current_page: number; last_page: number; total: number }
}

export const ROLES: { value: StaffRole; label: string; help: string }[] = [
  { value: 'cme', label: 'CME', help: 'CV, statistiques, passages, programme, emplacements, référentiels, notifications' },
  { value: 'direction', label: 'Direction', help: 'Consultation complète et export, aucune modification' },
  { value: 'administrateur', label: 'Administrateur', help: 'Tous les droits' },
]

export const STATUS_LABELS: Record<AccountStatus, string> = {
  actif: 'Actif',
  invitation: 'Invitation envoyée',
  desactive: 'Désactivé',
  supprime: 'Supprimé',
}

const accountsKey = ['accounts'] as const

export function useAccounts(filters: AccountFilters) {
  const params = new URLSearchParams()
  if (filters.search.trim()) params.set('search', filters.search.trim())
  if (filters.role) params.set('role', filters.role)
  if (filters.status) params.set('status', filters.status)
  if (filters.page > 1) params.set('page', String(filters.page))
  const query = params.toString()

  return useQuery({
    queryKey: [...accountsKey, query],
    queryFn: () => apiRequest<Paginated>(`/users${query ? `?${query}` : ''}`),
    placeholderData: keepPreviousData,
  })
}

/** Actions sans secret (le mot de passe réinitialisé passe par un appel direct, hors cache). */
export function useAccountMutations() {
  const queryClient = useQueryClient()
  const refresh = () => queryClient.invalidateQueries({ queryKey: accountsKey })

  const save = useMutation({
    mutationFn: ({ id, body }: { id: number | null; body: Record<string, unknown> }) =>
      id === null ? apiRequest('/users', { method: 'POST', body }) : apiRequest(`/users/${id}`, { method: 'PATCH', body }),
    onSuccess: refresh,
  })
  const action = useMutation({
    mutationFn: ({ id, name }: { id: number; name: 'deactivate' | 'reactivate' | 'restore' | 'resend-activation' | 'delete' }) =>
      name === 'delete'
        ? apiRequest(`/users/${id}`, { method: 'DELETE' })
        : apiRequest(`/users/${id}/${name}`, { method: 'POST' }),
    onSuccess: refresh,
  })

  return { save, action, refresh }
}

/** Réinitialisation : le mot de passe renvoyé n'est jamais conservé dans le cache TanStack. */
export async function resetPassword(id: number): Promise<string> {
  const response = await apiRequest<{ data: { password: string } }>(`/users/${id}/reset-password`, { method: 'POST' })
  return response.data.password
}
