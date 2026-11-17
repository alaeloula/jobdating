import type { QueryClient } from '@tanstack/react-query'
import { ApiError, apiRequest } from '../lib/api'
import { loggedOutQueryKey, meQueryKey, sessionExpiredQueryKey } from './queries'
import type { CurrentUser } from './types'

/**
 * Efface toutes les données du compte précédent côté client : requêtes (sauf les entrées
 * « auth », mises à jour pour que les écrans abonnés le voient) et envois terminés.
 * Utilisé après une déconnexion, et quand l'identité change (activation, décision B du 17/11/2026).
 */
export function clearClientSession(queryClient: QueryClient, nextUser: CurrentUser | null, loggedOut: boolean): void {
  queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== 'auth' })
  const mutations = queryClient.getMutationCache()
  mutations
    .getAll()
    .filter((mutation) => mutation.state.status !== 'pending')
    .forEach((mutation) => mutations.remove(mutation))
  queryClient.setQueryData(sessionExpiredQueryKey, false)
  queryClient.setQueryData(loggedOutQueryKey, loggedOut)
  queryClient.setQueryData(meQueryKey, nextUser)
}

/**
 * Déconnexion du compte présent sur ce navigateur avant d'utiliser un lien destiné à un autre
 * compte (décision B). Une session déjà fermée (401) compte comme une déconnexion réussie.
 */
export async function logoutOtherAccount(queryClient: QueryClient): Promise<void> {
  try {
    await apiRequest<void>('/logout', { method: 'POST', silent: true })
  } catch (error) {
    if (!(error instanceof ApiError && error.status === 401)) throw error
  }
  clearClientSession(queryClient, null, false)
}
