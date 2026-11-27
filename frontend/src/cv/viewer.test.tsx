import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import AppRoutes from '../AppRoutes'
import type { CurrentUser } from '../auth/types'
import type { Learner } from '../learners/api'
import { loadPdfjs, reloadPage } from './pdf'

// pdf.js est simulé : seule la recette sur navigateur prouve le worker et le rendu réels (plan T-21, § 6).
vi.mock('./pdf', async (original) => ({ ...(await original<typeof import('./pdf')>()), loadPdfjs: vi.fn(), reloadPage: vi.fn() }))

const ADMIN: CurrentUser = {
  id: 1, first_name: 'Admin', last_name: 'Fictif', email: 'admin@example.test', username: null,
  role: 'administrateur', role_label: 'Administrateur', permissions: ['learners.view', 'learners.update', 'cv.view', 'cv.upload', 'cv.history'], home: '/pilotage',
}
const DIRECTION: CurrentUser = { ...ADMIN, id: 3, role: 'direction', role_label: 'Direction', permissions: ['learners.view', 'cv.view'] }
const LEARNER: CurrentUser = { ...ADMIN, id: 7, first_name: 'Yasmine', last_name: 'Fictive', role: 'apprenant', role_label: 'Apprenant', permissions: ['cv.view'], home: '/ma-journee' }

const ref = (id: number, label: string) => ({ id, label, active: true })
const state = (version: string) => ({ version, source: 'televerse' as const, added_at: '2026-11-26T10:00:00Z', added_by_name: 'Admin Fictif', size: 2048 })

function learner(id: number, version: string | null): Learner {
  return {
    id, first_name: `Prénom${id}`, last_name: 'Fictif', email: `apprenant${id}@example.test`,
    campus: ref(1, 'Campus Fictif'), promotion: ref(1, 'Promotion Fictive'), school_class: ref(1, 'Classe Fictive'), filiere: ref(1, 'JAVA'),
    competences: [], status: 'actif', cv: version ? state(version) : null,
  }
}

function json(status: number, body?: unknown): Response {
  return new Response(body === undefined ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}
const pdf = () => new Response(new Uint8Array([37, 80, 68, 70]), { status: 200, headers: { 'Content-Type': 'application/pdf' } })

interface Call {
  method: string
  url: string
}

function mockApi(user: CurrentUser, handler: (call: Call) => Response | Promise<Response> | undefined) {
  const calls: Call[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const call: Call = { method: init?.method ?? 'GET', url: String(input) }
      calls.push(call)
      if (call.url === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
      if (call.url === '/api/v1/me') return json(200, { data: user })
      if (call.url.startsWith('/api/v1/referentials/')) return json(200, { data: [] })
      const response = await handler(call)
      if (response) return response
      throw new Error(`Appel inattendu : ${call.method} ${call.url}`)
    }),
  )
  return calls
}

let linkCount = 0
function link(version = 'v1') {
  linkCount += 1
  return json(200, { url: `/api/v1/cv-files/1?user=1&version=${version}&expires=1&signature=s${linkCount}`, expires_at: '2026-11-27T10:15:00Z', version })
}

/** pdf.js simulé : chaque page dépose son texte dans la couche de texte. */
function named(name: string, message: string): Error {
  const error = new Error(message)
  error.name = name
  return error
}

function fakePdfjs(options: { pages?: number; failDocument?: boolean; failWorker?: boolean; failRender?: boolean } = {}) {
  const documents: number[] = []
  const lib = {
    AnnotationMode: { ENABLE: 1 },
    getDocument: vi.fn(() => {
      documents.push(1)
      return {
        promise: options.failDocument
          ? Promise.reject(named('PasswordException', 'Mot de passe requis'))
          : options.failWorker
            ? Promise.reject(new Error('Setting up fake worker failed'))
            : Promise.resolve({
              numPages: options.pages ?? 2,
              getPage: async (n: number) => ({
                getViewport: ({ scale }: { scale: number }) => ({ width: 600 * scale, height: 800 * scale, scale }),
                render: () => ({ promise: options.failRender ? Promise.reject(new Error('rendu')) : Promise.resolve(), cancel: () => undefined }),
                streamTextContent: () => ({ page: n }),
              }),
            }),
        destroy: vi.fn(async () => undefined),
      }
    }),
    TextLayer: class {
      private readonly container: HTMLElement
      private readonly page: number
      constructor({ container, textContentSource }: { container: HTMLElement; textContentSource: { page: number } }) {
        this.container = container
        this.page = textContentSource.page
      }
      async render() {
        const span = document.createElement('span')
        span.textContent = `Texte du CV fictif, page ${this.page}`
        this.container.append(span)
      }
      cancel() {}
    },
  }
  vi.mocked(loadPdfjs).mockResolvedValue(lib as never)
  return { lib, documents }
}

function renderAt(path: string) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <AppRoutes />
      </MemoryRouter>
    </QueryClientProvider>,
  )
  return queryClient
}

beforeEach(() => {
  linkCount = 0
  vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:fictif'), revokeObjectURL: vi.fn() }))
})
afterEach(() => {
  vi.restoreAllMocks()
  vi.mocked(loadPdfjs).mockReset()
  vi.mocked(reloadPage).mockReset()
})

describe('Visionneuse du CV', () => {
  it('Mon CV : date, aperçu avec couche de texte, pages, zoom, plein écran au clavier, aucun téléversement', async () => {
    fakePdfjs()
    mockApi(LEARNER, (call) => {
      if (call.url === '/api/v1/me/cv') return json(200, { data: { version: 'v1', source: 'televerse', added_at: '2026-11-26T10:00:00Z' } })
      if (call.url === '/api/v1/me/cv/link') return link()
      if (call.url.startsWith('/api/v1/cv-files/1')) return pdf()
      return undefined
    })
    renderAt('/mon-cv')

    expect(await screen.findByText('CV disponible pour les entreprises · ajouté le 26/11/2026')).toBeInTheDocument()
    expect(await screen.findByText('Page 1 sur 2')).toBeInTheDocument()
    expect(await screen.findByText('Texte du CV fictif, page 1')).toBeInTheDocument()
    expect(screen.queryByLabelText(/Fichier PDF/)).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Page suivante' }))
    expect(await screen.findByText('Texte du CV fictif, page 2')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Page suivante' })).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: 'Zoom avant' }))

    fireEvent.click(screen.getByRole('button', { name: 'Ouvrir en plein écran' }))
    const dialog = await screen.findByRole('dialog', { name: 'CV de vous en plein écran' })
    await waitFor(() => expect(within(dialog).getByRole('button', { name: 'Quitter le plein écran' })).toHaveFocus())
    fireEvent.keyDown(dialog, { key: 'Escape' })
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    await waitFor(() => expect(screen.getByRole('button', { name: 'Ouvrir en plein écran' })).toHaveFocus())
  })

  it('Mon CV sans CV', async () => {
    mockApi(LEARNER, (call) => (call.url === '/api/v1/me/cv' ? json(200, { data: null }) : undefined))
    renderAt('/mon-cv')
    expect(await screen.findByText('Aucun CV validé pour l’instant.')).toBeInTheDocument()
    expect(screen.queryByRole('region', { name: /Aperçu du CV/ })).not.toBeInTheDocument()
  })

  it('fichier momentanément inaccessible puis Réessayer', async () => {
    fakePdfjs()
    let files = 0
    mockApi(LEARNER, (call) => {
      if (call.url === '/api/v1/me/cv') return json(200, { data: { version: 'v1', source: 'televerse', added_at: '2026-11-26T10:00:00Z' } })
      if (call.url === '/api/v1/me/cv/link') return link()
      if (call.url.startsWith('/api/v1/cv-files/1')) return ++files === 1 ? json(503, { code: 'cv_unavailable' }) : pdf()
      return undefined
    })
    renderAt('/mon-cv')

    expect(await screen.findByText('CV momentanément inaccessible.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }))
    expect(await screen.findByText('Page 1 sur 2')).toBeInTheDocument()
  })

  it('lien expiré renouvelé une seule fois, sans boucle', async () => {
    fakePdfjs()
    let files = 0
    const calls = mockApi(LEARNER, (call) => {
      if (call.url === '/api/v1/me/cv') return json(200, { data: { version: 'v1', source: 'televerse', added_at: '2026-11-26T10:00:00Z' } })
      if (call.url === '/api/v1/me/cv/link') return link()
      if (call.url.startsWith('/api/v1/cv-files/1')) return ++files <= 3 ? json(410, { code: 'link_expired' }) : pdf()
      return undefined
    })
    renderAt('/mon-cv')

    // 1er essai : expiré, renouvelé une fois, encore expiré → message (2 liens, pas de boucle).
    expect(await screen.findByText('Le lien du CV a expiré.')).toBeInTheDocument()
    expect(calls.filter((c) => c.url === '/api/v1/me/cv/link')).toHaveLength(2)
    // Réessayer : expiré puis renouvelé → affiché.
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }))
    expect(await screen.findByText('Page 1 sur 2')).toBeInTheDocument()
    expect(calls.filter((c) => c.url === '/api/v1/me/cv/link')).toHaveLength(4)
  })

  it('module pdf.js impossible à charger → recharger la page, pas de Réessayer', async () => {
    vi.mocked(loadPdfjs).mockRejectedValue(new Error('module'))
    mockApi(LEARNER, (call) => {
      if (call.url === '/api/v1/me/cv') return json(200, { data: { version: 'v1', source: 'televerse', added_at: '2026-11-26T10:00:00Z' } })
      if (call.url === '/api/v1/me/cv/link') return link()
      if (call.url.startsWith('/api/v1/cv-files/1')) return pdf()
      return undefined
    })
    renderAt('/mon-cv')

    expect(await screen.findByText('La visionneuse n’a pas pu se charger. Rechargez la page pour réessayer.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Réessayer' })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Recharger la page' }))
    expect(reloadPage).toHaveBeenCalledTimes(1)
  })

  it('document illisible ou rendu impossible : proposer le téléchargement', async () => {
    fakePdfjs({ failDocument: true })
    mockApi(LEARNER, (call) => {
      if (call.url === '/api/v1/me/cv') return json(200, { data: { version: 'v1', source: 'televerse', added_at: '2026-11-26T10:00:00Z' } })
      if (call.url === '/api/v1/me/cv/link') return link()
      if (call.url.startsWith('/api/v1/cv-files/1')) return pdf()
      return undefined
    })
    renderAt('/mon-cv')
    expect(await screen.findByText('Ce CV ne peut pas être affiché ici. Téléchargez-le.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Télécharger' })).toBeEnabled()
    expect(screen.queryByRole('button', { name: 'Réessayer' })).not.toBeInTheDocument()
  })

  it('rendu d’une page impossible', async () => {
    fakePdfjs({ failRender: true })
    mockApi(LEARNER, (call) => {
      if (call.url === '/api/v1/me/cv') return json(200, { data: { version: 'v1', source: 'televerse', added_at: '2026-11-26T10:00:00Z' } })
      if (call.url === '/api/v1/me/cv/link') return link()
      if (call.url.startsWith('/api/v1/cv-files/1')) return pdf()
      return undefined
    })
    renderAt('/mon-cv')
    expect(await screen.findByText('Ce CV ne peut pas être affiché ici. Téléchargez-le.')).toBeInTheDocument()
  })

  it('téléchargement : un seul envoi au double clic, nom explicite, libération différée', async () => {
    fakePdfjs()
    let finish: (r: Response) => void = () => undefined
    const calls = mockApi(LEARNER, (call) => {
      if (call.url === '/api/v1/me/cv') return json(200, { data: { version: 'v1', source: 'televerse', added_at: '2026-11-26T10:00:00Z' } })
      if (call.url === '/api/v1/me/cv/link') return link()
      if (call.url.includes('download=1')) {
        return new Promise<Response>((resolve) => {
          finish = resolve
        })
      }
      if (call.url.startsWith('/api/v1/cv-files/1')) return pdf()
      return undefined
    })
    const clicks: string[] = []
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      clicks.push(this.download)
    })
    renderAt('/mon-cv')

    const button = await screen.findByRole('button', { name: 'Télécharger' })
    fireEvent.click(button)
    fireEvent.click(button)
    await waitFor(() => expect(calls.filter((c) => c.url.includes('download=1'))).toHaveLength(1))
    finish(pdf())
    await waitFor(() => expect(clicks).toEqual(['CV-Yasmine-Fictive.pdf']))
    expect(URL.revokeObjectURL).not.toHaveBeenCalled()
  })

  it('CV des apprenants : remplacement pour le même apprenant, ancienne réponse ignorée', async () => {
    const { documents } = fakePdfjs()
    let version = 'v1'
    let releaseFirst: (r: Response) => void = () => undefined
    let files = 0
    const queryClient = renderAndMock()
    function renderAndMock() {
      mockApi(ADMIN, (call) => {
        if (call.url === '/api/v1/learners/search') return json(200, { data: [learner(1, version)], meta: { current_page: 1, last_page: 1, total: 1 }, counts: { without_cv: 0 } })
        if (call.url === '/api/v1/learners/1/cv/link') return link(version)
        if (call.url.startsWith('/api/v1/cv-files/1')) {
          files += 1
          if (files === 1) {
            return new Promise<Response>((resolve) => {
              releaseFirst = resolve
            })
          }
          return pdf()
        }
        return undefined
      })
      return renderAt('/cv')
    }

    fireEvent.click(await screen.findByRole('button', { name: 'Voir le CV de Prénom1 Fictif' }))
    await waitFor(() => expect(files).toBe(1))
    // Le CV est remplacé : la liste relue donne la version v2, la visionneuse recharge.
    version = 'v2'
    await queryClient.invalidateQueries({ queryKey: ['learners'] })
    expect(await screen.findByText('Page 1 sur 2')).toBeInTheDocument()
    expect(documents).toHaveLength(1)
    // L'ancienne réponse (v1) arrive en retard : ignorée.
    releaseFirst(pdf())
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(documents).toHaveLength(1)
  })

  // ------------------------------------------------ Revue Codex T-21 (constats 1 à 5)

  const myCvState = () => json(200, { data: { version: 'v1', source: 'televerse', added_at: '2026-11-26T10:00:00Z' } })

  it('constat 1 : téléchargement abandonné à la fermeture, aucun fichier créé', async () => {
    fakePdfjs()
    let finish: (r: Response) => void = () => undefined
    const calls = mockApi(ADMIN, (call) => {
      if (call.url === '/api/v1/learners/search') return json(200, { data: [learner(1, 'v1')], meta: { current_page: 1, last_page: 1, total: 1 }, counts: { without_cv: 0 } })
      if (call.url === '/api/v1/learners/1/cv/link') return link()
      if (call.url.includes('download=1')) {
        return new Promise<Response>((resolve) => {
          finish = resolve
        })
      }
      if (call.url.startsWith('/api/v1/cv-files/1')) return pdf()
      return undefined
    })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)
    renderAt('/cv')

    fireEvent.click(await screen.findByRole('button', { name: 'Voir le CV de Prénom1 Fictif' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Télécharger' }))
    // La requête du fichier est partie ; la visionneuse est fermée avant sa réponse.
    await waitFor(() => expect(calls.filter((c) => c.url.includes('download=1'))).toHaveLength(1))
    fireEvent.click(screen.getByRole('button', { name: 'Fermer' }))
    finish(pdf())
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(URL.createObjectURL).not.toHaveBeenCalled()
    expect(click).not.toHaveBeenCalled()
  })

  it('constat 1 : téléchargement réussi après expiration, fichier libéré après le délai', async () => {
    fakePdfjs()
    let downloads = 0
    mockApi(LEARNER, (call) => {
      if (call.url === '/api/v1/me/cv') return myCvState()
      if (call.url === '/api/v1/me/cv/link') return link()
      if (call.url.includes('download=1')) return ++downloads === 1 ? json(410, { code: 'link_expired' }) : pdf()
      if (call.url.startsWith('/api/v1/cv-files/1')) return pdf()
      return undefined
    })
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)
    const timers = vi.spyOn(window, 'setTimeout')
    renderAt('/mon-cv')

    fireEvent.click(await screen.findByRole('button', { name: 'Télécharger' }))
    await waitFor(() => expect(URL.createObjectURL).toHaveBeenCalledTimes(1))
    expect(downloads).toBe(2)
    expect(URL.revokeObjectURL).not.toHaveBeenCalled()
    const release = timers.mock.calls.find(([, delay]) => delay === 60_000)
    if (!release) throw new Error('Libération du fichier non programmée.')
    ;(release[0] as () => void)()
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:fictif')
  })

  it('constat 2 : plein écran modal — Tab tourne dedans, arrière-plan inerte, Échap après tabulations', async () => {
    fakePdfjs()
    mockApi(LEARNER, (call) => {
      if (call.url === '/api/v1/me/cv') return myCvState()
      if (call.url === '/api/v1/me/cv/link') return link()
      if (call.url.startsWith('/api/v1/cv-files/1')) return pdf()
      return undefined
    })
    renderAt('/mon-cv')

    fireEvent.click(await screen.findByRole('button', { name: 'Ouvrir en plein écran' }))
    const dialog = await screen.findByRole('dialog', { name: 'CV de vous en plein écran' })
    const download = within(dialog).getByRole('button', { name: 'Télécharger' })
    const previous = within(dialog).getByRole('button', { name: 'Page précédente' })
    // Les boutons désactivés ne comptent pas : le premier bouton actif est « Page suivante ».
    const first = within(dialog).getByRole('button', { name: 'Page suivante' })
    expect(previous).toBeDisabled()
    download.focus()
    fireEvent.keyDown(dialog, { key: 'Tab' })
    expect(first).toHaveFocus()
    fireEvent.keyDown(dialog, { key: 'Tab', shiftKey: true })
    expect(download).toHaveFocus()
    // Le reste de la page est inerte.
    expect([...document.body.children].filter((el) => el !== dialog && (el as HTMLElement).inert).length).toBeGreaterThan(0)

    fireEvent.keyDown(document, { key: 'Escape' })
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect([...document.body.children].some((el) => (el as HTMLElement).inert)).toBe(false)
    await waitFor(() => expect(screen.getByRole('button', { name: 'Ouvrir en plein écran' })).toHaveFocus())
  })

  // Contre-revue Codex T-21, N1 : pdf.js garde l'échec du worker pour toute la page ; seul un
  // rechargement complet le répare (vérifié par Codex dans Chrome). Le test vérifie l'action proposée.
  it('constat 3 / N1 : worker impossible à démarrer → « Recharger la page », téléchargement toujours possible', async () => {
    fakePdfjs({ failWorker: true })
    mockApi(LEARNER, (call) => {
      if (call.url === '/api/v1/me/cv') return myCvState()
      if (call.url === '/api/v1/me/cv/link') return link()
      if (call.url.startsWith('/api/v1/cv-files/1')) return pdf()
      return undefined
    })
    renderAt('/mon-cv')

    expect(await screen.findByText('La visionneuse n’a pas pu se charger. Rechargez la page pour réessayer.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Réessayer' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Télécharger' })).toBeEnabled()
    fireEvent.click(screen.getByRole('button', { name: 'Recharger la page' }))
    expect(reloadPage).toHaveBeenCalledTimes(1)
  })

  it('constat 4 : CV retiré avant le lien → état relu une fois, plus de bandeau « disponible »', async () => {
    fakePdfjs()
    let states = 0
    const calls = mockApi(LEARNER, (call) => {
      if (call.url === '/api/v1/me/cv') return ++states === 1 ? myCvState() : json(200, { data: null })
      if (call.url === '/api/v1/me/cv/link') return json(404, { message: 'CV non disponible.', code: 'no_cv' })
      return undefined
    })
    renderAt('/mon-cv')

    expect(await screen.findByText('Aucun CV validé pour l’instant.')).toBeInTheDocument()
    expect(screen.queryByText(/CV disponible pour les entreprises/)).not.toBeInTheDocument()
    expect(calls.filter((c) => c.url === '/api/v1/me/cv')).toHaveLength(2)
  })

  it('Direction : « Voir le CV » en lecture seule', async () => {
    fakePdfjs()
    mockApi(DIRECTION, (call) => {
      if (call.url === '/api/v1/learners/search') return json(200, { data: [learner(1, 'v1'), learner(2, null)], meta: { current_page: 1, last_page: 1, total: 2 }, counts: { without_cv: 1 } })
      if (call.url === '/api/v1/learners/1/cv/link') return link()
      if (call.url.startsWith('/api/v1/cv-files/1')) return pdf()
      return undefined
    })
    renderAt('/apprenants')

    const buttons = await screen.findAllByRole('button', { name: 'Voir le CV' })
    expect(buttons).toHaveLength(1)
    fireEvent.click(buttons[0])
    expect(await screen.findByText('Page 1 sur 2')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Téléverser|Remplacer|Retirer/ })).not.toBeInTheDocument()
  })
})
