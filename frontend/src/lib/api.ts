// Client de l'API JobDating : session par cookie Sanctum + protection CSRF (CDC 9.5).
// Les droits sont vérifiés par le serveur ; ce client ne fait que relayer ses réponses.

export type ApiEvent = 'unauthorized' | 'forbidden'

type ApiEventListener = (event: ApiEvent) => void

const listeners = new Set<ApiEventListener>()

/** Abonnement aux erreurs 401 et 403 de l'API (gestion globale de la session et des droits). */
export function onApiEvent(listener: ApiEventListener): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

function emit(event: ApiEvent): void {
  listeners.forEach((listener) => listener(event))
}

/** Fin de session détectée hors de `apiRequest` (ex. fichier du CV, T-21) : même traitement global. */
export function notifyUnauthorized(): void {
  emit('unauthorized')
}

export class ApiError extends Error {
  readonly status: number
  readonly code: string | undefined
  readonly errors: Record<string, string[]>
  readonly retryAfter: number | undefined
  /** Corps complet de la réponse d'erreur (champs propres à une route, ex. learners_count). */
  readonly payload: Record<string, unknown>

  constructor(
    status: number,
    message: string,
    options: {
      code?: string
      errors?: Record<string, string[]>
      retryAfter?: number
      payload?: Record<string, unknown>
    } = {},
  ) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = options.code
    this.errors = options.errors ?? {}
    this.retryAfter = options.retryAfter
    this.payload = options.payload ?? {}
  }
}

export const NETWORK_ERROR_MESSAGE = 'Connexion au serveur impossible. Vérifiez votre réseau et réessayez.'
const UNEXPECTED_ERROR_MESSAGE = 'Une erreur est survenue. Réessayez dans quelques instants.'

function readCookie(name: string): string | null {
  const prefix = `${name}=`
  const cookie = document.cookie.split('; ').find((item) => item.startsWith(prefix))
  return cookie ? decodeURIComponent(cookie.slice(prefix.length)) : null
}

/** Demande à Laravel le cookie XSRF-TOKEN. */
export async function ensureCsrfCookie(): Promise<void> {
  await fetch('/sanctum/csrf-cookie', {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  })
}

export interface ApiRequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  /** N'émet pas les événements 401 / 403 (connexion, lecture initiale de la session). */
  silent?: boolean
}

interface ErrorPayload {
  message?: unknown
  code?: unknown
  errors?: unknown
  retry_after?: unknown
}

function toApiError(status: number, payload: ErrorPayload | null): ApiError {
  return new ApiError(status, typeof payload?.message === 'string' ? payload.message : UNEXPECTED_ERROR_MESSAGE, {
    code: typeof payload?.code === 'string' ? payload.code : undefined,
    errors:
      payload?.errors && typeof payload.errors === 'object' ? (payload.errors as Record<string, string[]>) : undefined,
    retryAfter: typeof payload?.retry_after === 'number' ? payload.retry_after : undefined,
    payload: payload && typeof payload === 'object' ? (payload as Record<string, unknown>) : undefined,
  })
}

export async function apiRequest<T>(path: string, options: ApiRequestOptions = {}, isRetry = false): Promise<T> {
  const { method = 'GET', body, silent = false } = options
  const mutating = method !== 'GET'

  if (mutating && readCookie('XSRF-TOKEN') === null) {
    await ensureCsrfCookie()
  }

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  }
  // Fichier (FormData, ex. logo d'entreprise) : le navigateur fixe lui-même l'en-tête multipart.
  const isForm = typeof FormData !== 'undefined' && body instanceof FormData
  if (body !== undefined && !isForm) {
    headers['Content-Type'] = 'application/json'
  }
  const xsrf = readCookie('XSRF-TOKEN')
  if (mutating && xsrf !== null) {
    headers['X-XSRF-TOKEN'] = xsrf
  }

  let response: Response
  try {
    response = await fetch(`/api/v1${path}`, {
      method,
      headers,
      credentials: 'same-origin',
      body: body === undefined ? undefined : isForm ? (body as FormData) : JSON.stringify(body),
    })
  } catch {
    throw new ApiError(0, NETWORK_ERROR_MESSAGE, { code: 'network_error' })
  }

  // Jeton CSRF expiré : un nouveau jeton est demandé, puis la requête est rejouée une fois.
  if (response.status === 419 && !isRetry) {
    await ensureCsrfCookie()
    return apiRequest<T>(path, options, true)
  }

  if (response.status === 204) {
    return undefined as T
  }

  const payload: unknown = await response.json().catch(() => null)

  if (!response.ok) {
    const error = toApiError(response.status, payload as ErrorPayload | null)
    if (!silent && response.status === 401) emit('unauthorized')
    if (!silent && response.status === 403) emit('forbidden')
    throw error
  }

  return payload as T
}
