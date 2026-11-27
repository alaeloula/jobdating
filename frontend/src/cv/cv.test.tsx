import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import AppRoutes from '../AppRoutes'
import type { CurrentUser } from '../auth/types'
import type { Learner } from '../learners/api'

const CME: CurrentUser = {
  id: 2, first_name: 'Membre', last_name: 'Fictif', email: 'cme@example.test', username: null,
  role: 'cme', role_label: 'Équipe CME', permissions: ['learners.view', 'cv.view', 'cv.upload', 'cv.history', 'learners.competences.manage'], home: '/pilotage',
}
const DIRECTION: CurrentUser = { ...CME, id: 3, role: 'direction', role_label: 'Direction', permissions: ['learners.view', 'cv.view'] }

const ref = (id: number, label: string) => ({ id, label, active: true })

function learner(id: number, cv: Learner['cv'] = null): Learner {
  return {
    id, first_name: `Prénom${id}`, last_name: 'Fictif', email: `apprenant${id}@example.test`,
    campus: ref(1, 'YouCode Fictif'), promotion: ref(1, 'Promotion Fictive'), school_class: ref(1, 'Classe Fictive'), filiere: ref(1, 'JAVA'),
    competences: [], status: 'actif', cv,
  }
}

const UPLOADED = { source: 'televerse' as const, added_at: '2026-11-26T10:00:00Z', added_by_name: 'Membre Fictif', size: 2048 }

function json(status: number, body?: unknown): Response {
  return new Response(body === undefined ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

interface Call {
  method: string
  url: string
  body: unknown
}

function mockApi(user: CurrentUser, handler: (call: Call) => Response | Promise<Response> | undefined) {
  const calls: Call[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const raw = init?.body
      const call: Call = { method: init?.method ?? 'GET', url: String(input), body: raw instanceof FormData ? 'fichier' : raw ? JSON.parse(String(raw)) : undefined }
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

function renderAt(path: string) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <AppRoutes />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const pdf = () => new File(['%PDF-1.4 fictif'], 'cv-fictif.pdf', { type: 'application/pdf' })

async function choose(name: string) {
  fireEvent.change(await screen.findByLabelText(`Fichier PDF du CV de ${name}`), { target: { files: [pdf()] } })
}

function row(name: string) {
  return screen.getByText(name).closest('tr') as HTMLElement
}

afterEach(() => {
  vi.restoreAllMocks()
})

describe('CV des apprenants', () => {
  it('envoi réussi : message, liste et compteur relus', async () => {
    let hasCv = false
    const calls = mockApi(CME, (call) => {
      if (call.url === '/api/v1/learners/search') return json(200, { data: [learner(1, hasCv ? UPLOADED : null)], meta: { current_page: 1, last_page: 1, total: 1 }, counts: { without_cv: hasCv ? 0 : 1 } })
      if (call.method === 'POST' && call.url === '/api/v1/learners/1/cv') {
        hasCv = true
        return json(201, { data: UPLOADED, status: 'created', unchanged: false })
      }
      return undefined
    })
    renderAt('/cv')

    expect(await screen.findByRole('button', { name: 'Sans CV (1)' })).toBeInTheDocument()
    expect(screen.getByText('Aucun CV')).toBeInTheDocument()
    await choose('Prénom1 Fictif')

    expect(await screen.findByText('CV ajouté.')).toBeInTheDocument()
    expect(await screen.findByText('Téléversé le 26/11/2026')).toBeInTheDocument()
    expect(await screen.findByRole('button', { name: 'Sans CV (0)' })).toBeInTheDocument()
    expect(calls.filter((c) => c.url === '/api/v1/learners/1/cv')).toHaveLength(1)
  })

  it('erreur 422 puis nouveau choix du même fichier et nouvel essai', async () => {
    let attempts = 0
    mockApi(CME, (call) => {
      if (call.url === '/api/v1/learners/search') return json(200, { data: [learner(1)], meta: { current_page: 1, last_page: 1, total: 1 }, counts: { without_cv: 1 } })
      if (call.method === 'POST' && call.url === '/api/v1/learners/1/cv') {
        attempts += 1
        return attempts === 1
          ? json(422, { message: 'Le CV doit être un fichier PDF valide de 5 Mo au plus.', errors: { file: ['Le CV doit être un fichier PDF valide de 5 Mo au plus.'] } })
          : json(201, { data: UPLOADED, status: 'created', unchanged: false })
      }
      return undefined
    })
    renderAt('/cv')

    await choose('Prénom1 Fictif')
    expect(await screen.findByRole('alert')).toHaveTextContent('Le CV doit être un fichier PDF valide de 5 Mo au plus.')
    expect((screen.getByLabelText('Fichier PDF du CV de Prénom1 Fictif') as HTMLInputElement).value).toBe('')
    await choose('Prénom1 Fictif')
    expect(await screen.findByText('CV ajouté.')).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('un seul envoi à la fois par apprenant ; retrait bloqué pendant l’envoi', async () => {
    let finish: (r: Response) => void = () => undefined
    const calls = mockApi(CME, (call) => {
      if (call.url === '/api/v1/learners/search') return json(200, { data: [learner(1, UPLOADED)], meta: { current_page: 1, last_page: 1, total: 1 }, counts: { without_cv: 0 } })
      if (call.method === 'POST' && call.url === '/api/v1/learners/1/cv') {
        return new Promise<Response>((resolve) => {
          finish = resolve
        })
      }
      return undefined
    })
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    renderAt('/cv')

    await choose('Prénom1 Fictif')
    await choose('Prénom1 Fictif')
    await waitFor(() => expect(calls.filter((c) => c.method === 'POST' && c.url === '/api/v1/learners/1/cv')).toHaveLength(1))
    expect(screen.getByRole('button', { name: 'Envoi…' })).toBeDisabled()
    const remove = screen.getByRole('button', { name: 'Retirer' })
    expect(remove).toBeDisabled()
    fireEvent.click(remove)
    expect(confirm).not.toHaveBeenCalled()

    finish(json(200, { data: UPLOADED, status: 'replaced', unchanged: false }))
    expect(await screen.findByText('CV remplacé.')).toBeInTheDocument()
    expect(calls.filter((c) => c.method === 'DELETE')).toHaveLength(0)
  })

  it('retrait confirmé puis historique', async () => {
    let removed = false
    const calls = mockApi(CME, (call) => {
      if (call.url === '/api/v1/learners/search') return json(200, { data: [learner(1, removed ? null : UPLOADED)], meta: { current_page: 1, last_page: 1, total: 1 }, counts: { without_cv: removed ? 1 : 0 } })
      if (call.method === 'DELETE' && call.url === '/api/v1/learners/1/cv') {
        removed = true
        return json(200, { data: null })
      }
      if (call.url === '/api/v1/learners/1/cv/history?page=1') {
        return json(200, {
          data: [
            { id: 2, action: 'retire', source: 'televerse', author: 'Membre Fictif', size: null, reason: null, created_at: '2026-11-26T11:00:00Z' },
            { id: 1, action: 'televerse', source: 'televerse', author: 'Admin Fictif', size: 2048, reason: null, created_at: '2026-11-25T10:00:00Z' },
          ],
          meta: { current_page: 1, last_page: 1, total: 2 },
        })
      }
      return undefined
    })
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    renderAt('/cv')

    fireEvent.click(await screen.findByRole('button', { name: 'Retirer' }))
    expect(confirm).toHaveBeenCalledWith(expect.stringContaining('Prénom1 Fictif'))
    expect(await screen.findByText('CV retiré.')).toBeInTheDocument()
    expect(await screen.findByText('Aucun CV')).toBeInTheDocument()
    expect(calls.filter((c) => c.method === 'DELETE')).toHaveLength(1)

    fireEvent.click(screen.getByRole('button', { name: 'Historique' }))
    const panel = await screen.findByRole('complementary', { name: 'Historique du CV de Prénom1 Fictif' })
    expect(await within(panel).findByText('Retiré')).toBeInTheDocument()
    expect(within(panel).getByText(/Admin Fictif · 2 Ko/)).toBeInTheDocument()
  })

  it('réponse tardive après changement de filtre : rien sur une autre ligne', async () => {
    let finish: (r: Response) => void = () => undefined
    mockApi(CME, (call) => {
      if (call.url === '/api/v1/learners/search') {
        const body = call.body as { cv?: string }
        return body.cv === 'without'
          ? json(200, { data: [learner(2)], meta: { current_page: 1, last_page: 1, total: 1 }, counts: { without_cv: 1 } })
          : json(200, { data: [learner(1, UPLOADED), learner(2)], meta: { current_page: 1, last_page: 1, total: 2 }, counts: { without_cv: 1 } })
      }
      if (call.method === 'POST' && call.url === '/api/v1/learners/1/cv') {
        return new Promise<Response>((resolve) => {
          finish = resolve
        })
      }
      return undefined
    })
    renderAt('/cv')

    await choose('Prénom1 Fictif')
    fireEvent.click(await screen.findByRole('button', { name: 'Sans CV (1)' }))
    await waitFor(() => expect(screen.queryByText('Prénom1 Fictif')).not.toBeInTheDocument())
    finish(json(200, { data: UPLOADED, status: 'replaced', unchanged: false }))
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(within(row('Prénom2 Fictif')).queryByRole('status')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Sans CV (1)' }))
    expect(await screen.findByText('CV remplacé.')).toBeInTheDocument()
    expect(within(row('Prénom1 Fictif')).getByText('CV remplacé.')).toBeInTheDocument()
  })

  // Revue Codex T-20, constat 3.
  it('Apprenants : le panneau CV se ferme quand la liste change pendant un envoi', async () => {
    let finish: (r: Response) => void = () => undefined
    mockApi(CME, (call) => {
      if (call.url === '/api/v1/learners/search') {
        const body = call.body as { search?: string }
        return body.search
          ? json(200, { data: [learner(2)], meta: { current_page: 1, last_page: 1, total: 1 }, counts: { without_cv: 1 } })
          : json(200, { data: [learner(1), learner(2)], meta: { current_page: 1, last_page: 1, total: 2 }, counts: { without_cv: 2 } })
      }
      if (call.url.startsWith('/api/v1/learners/1/cv/history')) return json(200, { data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
      if (call.method === 'POST' && call.url === '/api/v1/learners/1/cv') {
        return new Promise<Response>((resolve) => {
          finish = resolve
        })
      }
      return undefined
    })
    renderAt('/apprenants')

    fireEvent.click(within(await screen.findByText('Prénom1 Fictif').then((n) => n.closest('tr') as HTMLElement)).getByRole('button', { name: 'CV' }))
    expect(await screen.findByRole('region', { name: 'CV de Prénom1 Fictif' })).toBeInTheDocument()
    await choose('Prénom1 Fictif')
    fireEvent.change(screen.getByLabelText('Rechercher un apprenant'), { target: { value: 'Prénom2' } })
    expect(screen.queryByRole('region', { name: 'CV de Prénom1 Fictif' })).not.toBeInTheDocument()

    finish(json(201, { data: UPLOADED, status: 'created', unchanged: false }))
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(screen.queryByRole('region', { name: 'CV de Prénom1 Fictif' })).not.toBeInTheDocument()
    expect(screen.queryByText('CV ajouté.')).not.toBeInTheDocument()
  })

  it('Direction : état du CV dans la liste, sans lien ni action', async () => {
    mockApi(DIRECTION, (call) =>
      call.url === '/api/v1/learners/search'
        ? json(200, { data: [learner(1, UPLOADED), learner(2)], meta: { current_page: 1, last_page: 1, total: 2 }, counts: { without_cv: 1 } })
        : undefined,
    )
    renderAt('/apprenants')

    expect(await screen.findByText('Téléversé le 26/11/2026')).toBeInTheDocument()
    expect(screen.getByText('Aucun CV')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'CV' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'CV des apprenants' })).not.toBeInTheDocument()
  })
})
