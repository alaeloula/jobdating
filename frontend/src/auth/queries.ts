import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiError, apiRequest } from '../lib/api'
import { clearClientSession } from './session'
import type { CurrentUser } from './types'

export const meQueryKey = ['auth', 'me'] as const

/** Indique que la session a été perdue en cours d'utilisation (message à l'écran de connexion). */
export const sessionExpiredQueryKey = ['auth', 'session-expired'] as const

/**
 * Indique une déconnexion volontaire : la page quittée n'est pas mémorisée comme
 * « page demandée » (le compte suivant sur le même poste arrive sur son propre accueil).
 */
export const loggedOutQueryKey = ['auth', 'logged-out'] as const

export async function fetchMe(): Promise<CurrentUser | null> {
  try {
    const response = await apiRequest<{ data: CurrentUser }>('/me', { silent: true })
    return response.data
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      return null
    }
    throw error
  }
}

export function useMe() {
  return useQuery({
    queryKey: meQueryKey,
    queryFn: fetchMe,
    staleTime: Infinity,
    retry: false,
  })
}

export interface LoginInput {
  identifier: string
  password: string
}

export function useLogin() {
  const queryClient = useQueryClient()

  return useMutation({
    // L'envoi contient le mot de passe saisi : il n'est pas conservé dans le cache des
    // mutations une fois l'écran de connexion fermé (revue Codex T-04, contre-revue).
    gcTime: 0,
    mutationFn: async (input: LoginInput) => {
      const response = await apiRequest<{ data: CurrentUser }>('/login', {
        method: 'POST',
        body: input,
        silent: true,
      })
      return response.data
    },
    onSuccess: (user) => {
      queryClient.setQueryData(sessionExpiredQueryKey, false)
      queryClient.setQueryData(loggedOutQueryKey, false)
      // L'utilisateur devient connecté : GuestOnly le dirige alors vers la page demandée
      // ou son accueil (un seul chemin de navigation).
      queryClient.setQueryData(meQueryKey, user)
    },
  })
}

export function useLogout() {
  const queryClient = useQueryClient()

  return useMutation({
    gcTime: 0,
    mutationFn: async () => {
      try {
        await apiRequest<void>('/logout', { method: 'POST', silent: true })
      } catch (error) {
        // 401 : le serveur n'a déjà plus de session, la déconnexion est effective.
        if (error instanceof ApiError && error.status === 401) return
        // Toute autre erreur (réseau, serveur) : la session peut être encore ouverte.
        throw error
      }
    },
    // Uniquement après une déconnexion confirmée par le serveur.
    onSuccess: () => {
      // Aucune donnée de l'utilisateur précédent ne reste en mémoire (requêtes et envois terminés :
      // identifiant, mot de passe, identité renvoyée). La déconnexion en cours est gardée.
      clearClientSession(queryClient, null, true)
    },
  })
}
