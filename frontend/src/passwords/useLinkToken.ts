import { useEffect, useState } from 'react'
import { useLocation, useNavigate } from 'react-router'
import { ApiError, apiRequest } from '../lib/api'

export type LinkStatus = 'checking' | 'valid' | 'invalid' | 'missing' | 'error'

/**
 * Jeton d'un lien reçu par e-mail (plan T-05 v3, section 3).
 *
 * - Le jeton arrive après « # » : il n'est jamais envoyé au serveur dans une adresse.
 * - Il est lu une fois, gardé UNIQUEMENT dans l'état de ce composant, puis retiré de la barre
 *   d'adresse (navigation interne du routeur, l'historique reste cohérent).
 * - Conservé après une erreur récupérable (422, 429, réseau) ; effacé au succès, après un lien
 *   invalide (410) et au départ de la page. Aucun stockage navigateur, aucun cache TanStack :
 *   un rechargement le perd (message « Ouvrez à nouveau le lien reçu par e-mail. »).
 */
export function useLinkToken(checkPath: '/activation/check' | '/password/reset/check') {
  const location = useLocation()
  const navigate = useNavigate()
  // Lu une seule fois, à l'ouverture de la page.
  const [initial] = useState<string | null>(() => (location.hash.length > 1 ? location.hash.slice(1) : null))
  const [token, setToken] = useState<string | null>(initial)
  const [status, setStatus] = useState<LinkStatus>(initial ? 'checking' : 'missing')
  const [firstName, setFirstName] = useState<string | null>(null)

  // Retire le jeton de l'adresse dès l'ouverture.
  useEffect(() => {
    if (location.hash) {
      navigate({ pathname: location.pathname, search: location.search }, { replace: true })
    }
  }, [location.hash, location.pathname, location.search, navigate])

  useEffect(() => {
    const value = initial
    if (!value) return
    let active = true
    apiRequest<{ data: { first_name?: string } }>(checkPath, { method: 'POST', body: { token: value }, silent: true })
      .then((response) => {
        if (!active) return
        setFirstName(response.data.first_name ?? null)
        setStatus('valid')
      })
      .catch((error: unknown) => {
        if (!active) return
        if (error instanceof ApiError && error.status === 410) {
          setToken(null)
          setStatus('invalid')
        } else {
          setStatus('error')
        }
      })
    return () => {
      active = false
    }
  }, [checkPath, initial])

  // Au départ de la page, l'état du composant (donc le jeton) disparaît avec lui.

  const invalidate = () => {
    setToken(null)
    setStatus('invalid')
  }

  return { token, status, firstName, invalidate, clear: () => setToken(null) }
}
