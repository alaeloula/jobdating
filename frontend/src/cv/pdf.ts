import type * as Pdfjs from 'pdfjs-dist'
import { apiRequest, notifyUnauthorized } from '../lib/api'

/**
 * Consultation du CV (T-21, plan v1.2). pdf.js est chargé À LA DEMANDE (aucun import statique dans le
 * chargement initial), avec son worker local de la même version (convention Vite `?url`, aucun service
 * externe). Le PDF est dessiné par pdf.js : son contenu n'est jamais exécuté (pas de gestionnaire de
 * scripts, d'actions ni de formulaires) et le lecteur PDF du navigateur n'est jamais utilisé.
 */
let library: Promise<typeof Pdfjs> | null = null

export function loadPdfjs(): Promise<typeof Pdfjs> {
  library ??= Promise.all([import('pdfjs-dist'), import('pdfjs-dist/build/pdf.worker.min.mjs?url')])
    .then(([pdfjs, worker]) => {
      pdfjs.GlobalWorkerOptions.workerSrc = worker.default
      return pdfjs
    })
    .catch((e: unknown) => {
      // Un nouvel essai doit pouvoir recharger le module (réseau momentanément coupé).
      library = null
      throw e
    })
  return library
}

/** Nom du fichier téléchargé, comme le serveur : `CV-Prenom-Nom.pdf` en ASCII, `CV.pdf` sinon. */
export function cvFileName(firstName: string, lastName: string): string {
  const slug = `${firstName} ${lastName}`
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[^A-Za-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 100)
  return slug === '' ? 'CV.pdf' : `CV-${slug}.pdf`
}

/**
 * Recharge la page : seul moyen fiable de repartir après une panne du worker de pdf.js, qui garde son
 * échec en mémoire pour toute la page (contre-revue Codex T-21, N1). Isolée pour les tests.
 */
export function reloadPage(): void {
  window.location.reload()
}

export interface CvLink {
  url: string
  expires_at: string
  version: string
}

/** `me` : le CV de l'apprenant connecté ; sinon l'identifiant d'un apprenant. */
export type CvOwner = number | 'me'

export async function requestCvLink(owner: CvOwner): Promise<CvLink> {
  return apiRequest<CvLink>(owner === 'me' ? '/me/cv/link' : `/learners/${owner}/cv/link`, { method: 'POST' })
}

export type CvFailure = 'expired' | 'denied' | 'outdated' | 'unavailable' | 'no_cv'

export class CvFetchError extends Error {
  readonly kind: CvFailure

  constructor(kind: CvFailure) {
    super(kind)
    this.name = 'CvFetchError'
    this.kind = kind
  }
}

/**
 * Octets du PDF par l'URL signée (hors `apiRequest`, qui lit du JSON et renvoie à l'accueil sur tout
 * 403). 401 : fin de session par le mécanisme existant ; 410 : lien expiré (renouvelable une fois par
 * l'appelant) ; 403 et 404 `not_found` : refus ; 404 `cv_outdated` : version remplacée ou retirée ;
 * 503 et réseau : momentanément inaccessible.
 */
export async function fetchCvPdf(url: string, download = false, signal?: AbortSignal): Promise<ArrayBuffer> {
  let response: Response
  try {
    response = await fetch(download ? `${url}&download=1` : url, { credentials: 'same-origin', headers: { Accept: 'application/pdf, application/json' }, signal })
  } catch {
    throw new CvFetchError('unavailable')
  }
  if (response.ok) return response.arrayBuffer()
  if (response.status === 401) {
    notifyUnauthorized()
    throw new CvFetchError('denied')
  }
  if (response.status === 410) throw new CvFetchError('expired')
  const payload = (await response.json().catch(() => null)) as { code?: string } | null
  if (response.status === 404 && payload?.code === 'cv_outdated') throw new CvFetchError('outdated')
  if (response.status === 403 || response.status === 404) throw new CvFetchError('denied')
  throw new CvFetchError('unavailable')
}

/** Lien puis octets ; un lien expiré est renouvelé UNE seule fois (pas de boucle). */
export async function loadCvBytes(owner: CvOwner, download = false, signal?: AbortSignal): Promise<ArrayBuffer> {
  for (let attempt = 0; ; attempt++) {
    let link: CvLink
    try {
      link = await requestCvLink(owner)
    } catch (e) {
      const status = (e as { status?: number }).status
      const code = (e as { code?: string }).code
      if (status === 404 && code === 'no_cv') throw new CvFetchError('no_cv')
      if (status === 0 || status === undefined || status >= 500) throw new CvFetchError('unavailable')
      throw new CvFetchError('denied')
    }
    try {
      return await fetchCvPdf(link.url, download, signal)
    } catch (e) {
      if (e instanceof CvFetchError && e.kind === 'expired' && attempt === 0) continue
      throw e
    }
  }
}
