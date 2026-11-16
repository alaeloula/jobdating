import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiRequest } from '../lib/api'
import type { ReferentialType } from './config'

/** Valeur d'un référentiel, telle que renvoyée par la gestion (champs propres + état). */
export interface ReferentialValue {
  id: number
  display_label: string
  active: boolean
  position?: number
  [field: string]: unknown
}

export interface ReferentialOption {
  id: number
  label: string
  active: boolean
}

export const referentialKey = (type: ReferentialType) => ['referentials', type] as const

export function useReferentialValues(type: ReferentialType) {
  return useQuery({
    queryKey: referentialKey(type),
    queryFn: async () => (await apiRequest<{ data: ReferentialValue[] }>(`/referentials/${type}`)).data,
  })
}

/** Options (saisie : actives seulement ; `withInactive` pour afficher une valeur déjà liée). */
export function useReferentialOptions(type: ReferentialType, withInactive = false) {
  return useQuery({
    queryKey: [...referentialKey(type), 'options', withInactive],
    queryFn: async () =>
      (await apiRequest<{ data: ReferentialOption[] }>(`/referentials/${type}/options${withInactive ? '?inactives=1' : ''}`)).data,
  })
}

export function useReferentialMutations(type: ReferentialType) {
  const queryClient = useQueryClient()
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['referentials'] })

  const create = useMutation({
    mutationFn: (body: Record<string, unknown>) => apiRequest(`/referentials/${type}`, { method: 'POST', body }),
    onSuccess: refresh,
  })
  const update = useMutation({
    mutationFn: ({ id, body }: { id: number; body: Record<string, unknown> }) =>
      apiRequest(`/referentials/${type}/${id}`, { method: 'PATCH', body }),
    onSuccess: refresh,
  })
  const reorder = useMutation({
    mutationFn: (body: { ids: number[]; type?: string }) => apiRequest(`/referentials/${type}/order`, { method: 'PUT', body }),
    onSuccess: refresh,
  })

  return { create, update, reorder }
}
