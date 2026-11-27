import { useEffect, useRef, useState, type KeyboardEvent } from 'react'
import { createPortal } from 'react-dom'
import type { PDFDocumentLoadingTask, PDFDocumentProxy, RenderTask, TextLayer } from 'pdfjs-dist'
import { CvFetchError, loadCvBytes, loadPdfjs, reloadPage, type CvFailure, type CvOwner } from './pdf'

const button = 'min-h-9 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold hover:bg-slate-50 disabled:opacity-50'
const MIN_ZOOM = 0.5
const MAX_ZOOM = 3
const MAX_WIDTH = 1600
/** Délai avant de libérer le fichier téléchargé : le navigateur doit avoir pris la ressource (contre-revue, N3). */
const DOWNLOAD_RELEASE_MS = 60_000

type Failure = CvFailure | 'viewer' | 'unreadable'
type Status = { kind: 'loading' } | { kind: 'ready'; pages: number } | { kind: 'error'; failure: Failure }

const FAILURE_TEXT: Record<Failure, string> = {
  unavailable: 'CV momentanément inaccessible.',
  expired: 'Le lien du CV a expiré.',
  denied: 'Accès au CV refusé.',
  outdated: 'Ce CV a été remplacé ou retiré.',
  no_cv: 'CV non disponible.',
  viewer: 'La visionneuse n’a pas pu se charger. Rechargez la page pour réessayer.',
  unreadable: 'Ce CV ne peut pas être affiché ici. Téléchargez-le.',
}
/** Pannes passagères qu'un nouvel essai répare. La panne de la visionneuse (`viewer`) demande de recharger la page. */
const RETRY: Failure[] = ['unavailable', 'expired']
/** Erreurs de pdf.js propres au DOCUMENT (illisible, protégé) ; toute autre erreur est une panne de la visionneuse (worker…), réessayable. */
const DOCUMENT_ERRORS = ['InvalidPDFException', 'PasswordException', 'MissingPDFException', 'UnknownErrorException', 'FormatError']
/** Retrait ou remplacement détecté : l'état affiché autour de la visionneuse doit être relu. */
const STALE: Failure[] = ['outdated', 'no_cv']
const FOCUSABLE = 'button:not([disabled]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'

interface Props {
  owner: CvOwner
  /** Identité de chargement (avec `owner`) : une nouvelle version recharge, l'ancienne est abandonnée. */
  version: string
  /** Nom du fichier téléchargé, ex. `CV-Prenom-Nom.pdf`. */
  fileName: string
  /** Nom affiché : « Aperçu du CV de [title] ». */
  title: string
  /** Appelé si le serveur signale que la version affichée a été remplacée ou retirée. */
  onOutdated?: () => void
}

/**
 * Visionneuse du CV (CV-03, APP-03 ; plan T-21 v1.2, § 4). Rendu pdf.js page par page, couche de texte
 * pour les lecteurs d'écran et la sélection, commandes au clavier, plein écran DANS le site.
 * L'identité de chargement est apprenant + version : toute réponse d'un chargement abandonné est ignorée.
 */
export default function CvViewer({ owner, version, fileName, title, onOutdated }: Props) {
  const [attempt, setAttempt] = useState(0)
  const [status, setStatus] = useState<Status>({ kind: 'loading' })
  const [page, setPage] = useState(1)
  const [zoom, setZoom] = useState(1)
  const [fullscreen, setFullscreen] = useState(false)
  const [downloading, setDownloading] = useState(false)
  const [downloadError, setDownloadError] = useState<string | null>(null)
  const documentRef = useRef<PDFDocumentProxy | null>(null)
  const frame = useRef<HTMLDivElement>(null)
  const canvas = useRef<HTMLCanvasElement>(null)
  const textLayer = useRef<HTMLDivElement>(null)
  const fullscreenButton = useRef<HTMLButtonElement>(null)
  const closeButton = useRef<HTMLButtonElement>(null)
  const outdated = useRef(onOutdated)
  useEffect(() => {
    outdated.current = onOutdated
  }, [onOutdated])
  const downloadingRef = useRef(false)
  const dialog = useRef<HTMLDivElement>(null)
  // Visionneuse encore montée : un téléchargement abandonné (fermeture, autre apprenant ou version) ne
  // crée ni fichier ni lien ensuite (revue Codex T-21, constat 1).
  const alive = useRef(true)
  const downloadAbort = useRef<AbortController | null>(null)
  useEffect(() => {
    alive.current = true
    return () => {
      alive.current = false
      downloadAbort.current?.abort()
    }
  }, [])

  // Chargement du document : octets par lien signé, puis pdf.js (sans scripts, actions ni formulaires).
  // Les utilisateurs du composant le remontent (clé apprenant + version) à chaque changement d'identité ;
  // « Réessayer » remet l'état de chargement avant d'incrémenter `attempt`.
  useEffect(() => {
    let cancelled = false
    let task: PDFDocumentLoadingTask | null = null
    void (async () => {
      let bytes: ArrayBuffer
      try {
        bytes = await loadCvBytes(owner)
      } catch (e) {
        if (cancelled) return
        const failure = e instanceof CvFetchError ? e.kind : 'unavailable'
        setStatus({ kind: 'error', failure })
        // Retiré avant même l'émission du lien (`no_cv`) ou version remplacée : état relu une fois (constat 4).
        if (STALE.includes(failure)) outdated.current?.()
        return
      }
      if (cancelled) return
      let pdfjs: Awaited<ReturnType<typeof loadPdfjs>>
      try {
        pdfjs = await loadPdfjs()
      } catch {
        if (!cancelled) setStatus({ kind: 'error', failure: 'viewer' })
        return
      }
      if (cancelled) return
      try {
        task = pdfjs.getDocument({ data: new Uint8Array(bytes), enableXfa: false })
        const document = await task.promise
        // Abandonné entre-temps : le nettoyage détruit déjà la tâche (et donc le document).
        if (cancelled) return
        documentRef.current = document
        setStatus({ kind: 'ready', pages: document.numPages })
      } catch (e) {
        if (cancelled) return
        // Worker impossible à démarrer (« Setting up fake worker failed ») : panne réessayable, pas un
        // document illisible (revue Codex T-21, constat 3). La tâche inutile est détruite.
        const name = (e as { name?: string }).name ?? ''
        void task?.destroy()
        task = null
        setStatus({ kind: 'error', failure: DOCUMENT_ERRORS.includes(name) ? 'unreadable' : 'viewer' })
      }
    })()
    return () => {
      cancelled = true
      documentRef.current = null
      // Détruire la tâche de chargement libère aussi le document et son worker (pdf.js 6).
      void task?.destroy()
    }
  }, [owner, version, attempt])

  // Rendu de la page courante : une seule surface, taille bornée ; tâches annulées au changement.
  const ready = status.kind === 'ready'
  useEffect(() => {
    const document = documentRef.current
    if (!ready || !document || !canvas.current || !textLayer.current) return
    let cancelled = false
    let render: RenderTask | null = null
    let text: TextLayer | null = null
    void (async () => {
      try {
        const pdfjs = await loadPdfjs()
        const pdfPage = await document.getPage(page)
        if (cancelled || !canvas.current || !textLayer.current) return
        const available = Math.min(frame.current?.clientWidth || 600, MAX_WIDTH)
        const base = pdfPage.getViewport({ scale: 1 })
        const viewport = pdfPage.getViewport({ scale: (available / base.width) * zoom })
        const ratio = Math.min(window.devicePixelRatio || 1, 2)
        const surface = canvas.current
        surface.width = Math.floor(viewport.width * ratio)
        surface.height = Math.floor(viewport.height * ratio)
        surface.style.width = `${Math.floor(viewport.width)}px`
        surface.style.height = `${Math.floor(viewport.height)}px`
        render = pdfPage.render({ canvas: surface, viewport, transform: ratio === 1 ? undefined : [ratio, 0, 0, ratio, 0, 0], annotationMode: pdfjs.AnnotationMode.ENABLE })
        await render.promise
        if (cancelled || !textLayer.current) return
        const container = textLayer.current
        container.replaceChildren()
        container.style.setProperty('--total-scale-factor', String(viewport.scale))
        container.style.width = surface.style.width
        container.style.height = surface.style.height
        text = new pdfjs.TextLayer({ textContentSource: pdfPage.streamTextContent(), container, viewport })
        await text.render()
      } catch (e) {
        if (!cancelled && (e as { name?: string }).name !== 'RenderingCancelledException' && (e as { name?: string }).name !== 'AbortException') {
          setStatus({ kind: 'error', failure: 'unreadable' })
        }
      }
    })()
    return () => {
      cancelled = true
      render?.cancel()
      text?.cancel()
    }
  }, [ready, page, zoom, fullscreen])

  // Plein écran MODAL (revue Codex T-21, constat 2) : tout le reste de la page devient inerte (ni focus
  // ni clic) et Échap le ferme où que soit le focus ; rétabli à la sortie, avant le retour du focus.
  useEffect(() => {
    if (!fullscreen) return
    const host = dialog.current
    const others = [...window.document.body.children].filter((el): el is HTMLElement => el !== host && el instanceof HTMLElement && !el.inert)
    others.forEach((el) => {
      el.inert = true
    })
    const escape = (e: globalThis.KeyboardEvent) => {
      if (e.key === 'Escape') setFullscreen(false)
    }
    window.document.addEventListener('keydown', escape)
    return () => {
      window.document.removeEventListener('keydown', escape)
      others.forEach((el) => {
        el.inert = false
      })
    }
  }, [fullscreen])

  /** Tab et Maj+Tab restent dans le plein écran. */
  const trapTab = (e: KeyboardEvent<HTMLDivElement>) => {
    if (e.key !== 'Tab' || !dialog.current) return
    const items = [...dialog.current.querySelectorAll<HTMLElement>(FOCUSABLE)]
    if (items.length === 0) return
    const first = items[0]
    const last = items[items.length - 1]
    if (e.shiftKey && window.document.activeElement === first) {
      e.preventDefault()
      last.focus()
    } else if (!e.shiftKey && window.document.activeElement === last) {
      e.preventDefault()
      first.focus()
    }
  }

  // Focus du plein écran : dans la visionneuse à l'ouverture, rendu au bouton à la sortie.
  const wasFullscreen = useRef(false)
  useEffect(() => {
    if (fullscreen) closeButton.current?.focus()
    else if (wasFullscreen.current) fullscreenButton.current?.focus()
    wasFullscreen.current = fullscreen
  }, [fullscreen])

  const download = async () => {
    if (downloadingRef.current) return
    downloadingRef.current = true
    setDownloading(true)
    setDownloadError(null)
    const abort = new AbortController()
    downloadAbort.current = abort
    try {
      const bytes = await loadCvBytes(owner, true, abort.signal)
      // Visionneuse fermée ou remplacée entre-temps : aucun fichier n'est créé ni enregistré.
      if (!alive.current || abort.signal.aborted) return
      const url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }))
      const link = window.document.createElement('a')
      link.href = url
      link.download = fileName
      window.document.body.append(link)
      link.click()
      link.remove()
      window.setTimeout(() => URL.revokeObjectURL(url), DOWNLOAD_RELEASE_MS)
    } catch (e) {
      if (!alive.current || abort.signal.aborted) return
      const failure = e instanceof CvFetchError ? e.kind : 'unavailable'
      setDownloadError(`Téléchargement impossible : ${FAILURE_TEXT[failure]}`)
      if (STALE.includes(failure)) outdated.current?.()
    } finally {
      downloadingRef.current = false
      if (downloadAbort.current === abort) downloadAbort.current = null
      if (alive.current) setDownloading(false)
    }
  }

  const pages = status.kind === 'ready' ? status.pages : 0
  const toolbar = (
    <div className="flex flex-wrap items-center gap-2">
      {ready && (
        <>
          <button type="button" className={button} disabled={page <= 1} onClick={() => setPage((p) => p - 1)} aria-label="Page précédente">
            ‹
          </button>
          <span className={`text-sm ${fullscreen ? 'text-white' : ''}`} aria-live="polite">
            Page {page} sur {pages}
          </span>
          <button type="button" className={button} disabled={page >= pages} onClick={() => setPage((p) => p + 1)} aria-label="Page suivante">
            ›
          </button>
          <button type="button" className={button} disabled={zoom <= MIN_ZOOM} onClick={() => setZoom((z) => Math.max(MIN_ZOOM, z - 0.25))} aria-label="Zoom arrière">
            −
          </button>
          <button type="button" className={button} disabled={zoom >= MAX_ZOOM} onClick={() => setZoom((z) => Math.min(MAX_ZOOM, z + 0.25))} aria-label="Zoom avant">
            +
          </button>
          {fullscreen ? (
            <button type="button" ref={closeButton} className={button} onClick={() => setFullscreen(false)}>
              Quitter le plein écran
            </button>
          ) : (
            <button type="button" ref={fullscreenButton} className={button} onClick={() => setFullscreen(true)}>
              Ouvrir en plein écran
            </button>
          )}
        </>
      )}
      <button type="button" className={button} disabled={downloading} onClick={() => void download()}>
        {downloading ? 'Téléchargement…' : 'Télécharger'}
      </button>
    </div>
  )

  const body = (
    <>
      {toolbar}
      {downloadError && (
        <p role="alert" className="text-sm text-red-800">
          {downloadError}
        </p>
      )}
      {status.kind === 'loading' && (
        <p role="status" className="text-sm text-slate-600">
          Chargement du CV…
        </p>
      )}
      {status.kind === 'error' && (
        <div role="alert" className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
          <p>{FAILURE_TEXT[status.failure]}</p>
          {RETRY.includes(status.failure) && (
            <button
              type="button"
              className={`${button} mt-2`}
              onClick={() => {
                setStatus({ kind: 'loading' })
                setPage(1)
                setAttempt((a) => a + 1)
              }}
            >
              Réessayer
            </button>
          )}
          {status.failure === 'viewer' && (
            <button type="button" className={`${button} mt-2`} onClick={reloadPage}>
              Recharger la page
            </button>
          )}
        </div>
      )}
      <div ref={frame} className={`overflow-auto rounded-lg border border-slate-200 bg-slate-100 ${ready ? '' : 'hidden'} ${fullscreen ? 'flex-1' : 'max-h-[75vh]'}`}>
        <div className="relative mx-auto w-fit bg-white shadow">
          <canvas ref={canvas} aria-hidden="true" />
          <div ref={textLayer} className="textLayer" />
        </div>
      </div>
    </>
  )

  // Le plein écran est rendu directement dans <body> : le reste de la page peut alors être rendu inerte.
  return fullscreen ? (
    createPortal(
      <div ref={dialog} role="dialog" aria-modal="true" aria-label={`CV de ${title} en plein écran`} className="fixed inset-0 z-50 flex flex-col gap-2 bg-slate-900/95 p-3" onKeyDown={trapTab}>
        {body}
      </div>,
      window.document.body,
    )
  ) : (
    <section aria-label={`Aperçu du CV de ${title}`} className="space-y-2">
      {body}
    </section>
  )
}
