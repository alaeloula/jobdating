import { useEffect, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Navigate, Outlet, useLocation, useNavigate } from 'react-router'
import { onApiEvent } from '../lib/api'
import { loggedOutQueryKey, meQueryKey, sessionExpiredQueryKey, useMe } from './queries'
import {
  FORBIDDEN_MESSAGE,
  type CurrentUser,
  type FlashLocationState,
  type LoginLocationState,
  type RoleName,
} from './types'

function FullPageMessage({ children }: { children: ReactNode }) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-100 p-4 text-slate-600" role="status">
      {children}
    </div>
  )
}

/**
 * Réagit aux erreurs 401 / 403 de l'API.
 * - 401 : la session est perdue, l'utilisateur est renvoyé à la connexion (avec message).
 * - 403 : retour à l'accueil du rôle avec un message (RG-ACC-03).
 */
export function ApiEventsListener() {
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  useEffect(
    () =>
      onApiEvent((event) => {
        const user = queryClient.getQueryData<CurrentUser | null>(meQueryKey)

        if (event === 'unauthorized') {
          if (user) {
            queryClient.setQueryData(sessionExpiredQueryKey, true)
          }
          queryClient.setQueryData(meQueryKey, null)
          return
        }

        const state: FlashLocationState = { flash: FORBIDDEN_MESSAGE }
        navigate(user?.home ?? '/', { replace: true, state })
      }),
    [queryClient, navigate],
  )

  return null
}

/**
 * Accès réservé aux utilisateurs connectés, et éventuellement à certains rôles.
 * Il ne fait que guider l'interface : le serveur vérifie chaque droit (RG-ACC-01).
 */
export function RequireAuth({ roles }: { roles?: RoleName[] }) {
  const location = useLocation()
  const queryClient = useQueryClient()
  const { data: user, isPending, isError } = useMe()

  if (isPending) {
    return <FullPageMessage>Chargement…</FullPageMessage>
  }

  if (isError) {
    return <FullPageMessage>Le serveur ne répond pas. Réessayez dans quelques instants.</FullPageMessage>
  }

  if (!user) {
    // Déconnexion volontaire : pas de « page demandée » à retrouver.
    if (queryClient.getQueryData<boolean>(loggedOutQueryKey) === true) {
      return <Navigate to="/login" replace />
    }

    const state: LoginLocationState = {
      from: { pathname: location.pathname, search: location.search },
      expired: queryClient.getQueryData<boolean>(sessionExpiredQueryKey) === true,
    }
    return <Navigate to="/login" replace state={state} />
  }

  if (roles && !roles.includes(user.role)) {
    const state: FlashLocationState = { flash: FORBIDDEN_MESSAGE }
    return <Navigate to={user.home} replace state={state} />
  }

  return <Outlet />
}

/**
 * Écran réservé aux visiteurs. Un utilisateur connecté est dirigé vers la page demandée
 * avant la connexion (AUTH-01, paramètres compris), sinon vers son accueil (RG-AUTH-03).
 *
 * C'est le seul endroit qui navigue après une connexion réussie : l'écran de connexion
 * met seulement l'utilisateur à jour.
 */
export function GuestOnly({ children }: { children: ReactNode }) {
  const location = useLocation()
  const { data: user, isPending } = useMe()

  if (isPending) {
    return <FullPageMessage>Chargement…</FullPageMessage>
  }

  if (user) {
    // Seuls des chemins internes sont acceptés (pas d'URL externe).
    const from = (location.state as LoginLocationState | null)?.from
    const target =
      from && from.pathname.startsWith('/') && !from.pathname.startsWith('//') && from.pathname !== '/login'
        ? `${from.pathname}${from.search ?? ''}`
        : user.home
    return <Navigate to={target} replace />
  }

  return children
}

/** Racine du site : accueil du rôle, ou connexion. */
export function RootRedirect() {
  const { data: user, isPending, isError } = useMe()

  if (isPending) {
    return <FullPageMessage>Chargement…</FullPageMessage>
  }

  if (isError) {
    return <FullPageMessage>Le serveur ne répond pas. Réessayez dans quelques instants.</FullPageMessage>
  }

  return <Navigate to={user ? user.home : '/login'} replace />
}
