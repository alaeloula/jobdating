import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import AppRoutes from '../AppRoutes'
import type { CurrentUser, RoleName } from '../auth/types'
import type { Learner } from './api'

const PASSWORD = 'MotDePasseFictif9'

function user(role: RoleName, permissions: string[]): CurrentUser {
  return { id: 1, first_name: 'Compte', last_name: 'Fictif', email: 'compte@example.test', username: null, role, role_label: role, permissions, home: '/pilotage' }
}

const ADMIN = user('administrateur', ['learners.view', 'learners.update', 'learners.view_cnie', 'learners.competences.manage'])
const CME = user('cme', ['learners.view', 'learners.view_cnie', 'learners.competences.manage'])
const DIRECTION = user('direction', ['learners.view', 'learners.view_cnie'])

const ref = (id: number, label: string, active = true) => ({ id, label, active })

function learner(id: number, firstName: string, extra: Partial<Learner> = {}): Learner {
  return {
    id, first_name: firstName, last_name: 'Fictif', email: `${firstName.toLowerCase()}@example.test`,
    campus: ref(1, 'Campus Fictif'), promotion: ref(2, 'Promotion Fictive'), school_class: ref(3, 'Classe Fictive'),
    filiere: ref(4, 'JAVA – Java'), competences: [], status: 'actif', ...extra,
  }
}

const adminRow = (id: number, name: string, extra: Partial<Learner> = {}) =>
  learner(id, name, { cnie: `ZZ90000${id}`, phone: '0600000000', cme_learner_id: null, ...extra })

interface Call {
  method: string
  url: string
  body: Record<string, unknown> | undefined
}

function json(status: number, body?: unknown): Response {
  return new Response(body === undefined ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

function mockApi(me: CurrentUser, rows: Learner[], handler: (call: Call) => Response | undefined = () => undefined) {
  const calls: Call[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const call: Call = { method: init?.method ?? 'GET', url: String(input), body: init?.body ? JSON.parse(String(init.body)) : undefined }
      calls.push(call)
      if (call.url === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
      if (call.url === '/api/v1/me') return json(200, { data: me })
      const custom = handler(call)
      if (custom) return custom
      if (call.url.startsWith('/api/v1/referentials/competences/options')) return json(200, { data: [ref(7, 'SQL')] })
      if (call.url.startsWith('/api/v1/referentials/classes/options')) return json(200, { data: [{ ...ref(3, 'Classe Fictive'), filiere_id: 4 }] })
      if (call.url.startsWith('/api/v1/referentials/filieres/options')) return json(200, { data: [ref(4, 'JAVA – Java')] })
      if (call.url.startsWith('/api/v1/referentials/campus/options')) return json(200, { data: [ref(1, 'Campus Fictif')] })
      if (call.url.startsWith('/api/v1/referentials/promotions/options')) return json(200, { data: [ref(2, 'Promotion Fictive')] })
      if (call.url === '/api/v1/learners/search') return json(200, { data: rows, meta: { current_page: 1, last_page: 1, total: rows.length } })
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
  return queryClient
}

function cacheHas(queryClient: QueryClient, secret: string): boolean {
  return JSON.stringify([
    queryClient.getQueryCache().getAll().map((q) => q.state.data ?? null),
    queryClient.getMutationCache().getAll().map((m) => [m.state.variables ?? null, m.state.data ?? null]),
  ]).includes(secret)
}

describe('Apprenants', () => {
  it('administrateur : liste par POST, CNIE complète, ajout avec mot de passe affiché une fois et hors cache', async () => {
    const created = adminRow(9, 'Nouvel')
    const calls = mockApi(ADMIN, [adminRow(1, 'Alice')], (call) =>
      call.method === 'POST' && call.url === '/api/v1/learners' ? json(201, { data: created, password: PASSWORD }) : undefined,
    )
    const queryClient = renderAt('/apprenants')

    expect(await screen.findByText('ZZ900001')).toBeInTheDocument()
    const search = calls.find((c) => c.url === '/api/v1/learners/search')
    expect(search?.method).toBe('POST')
    expect(search?.body).toMatchObject({ status: 'actif', page: 1 })

    fireEvent.click(screen.getByRole('button', { name: 'Ajouter un apprenant' }))
    fireEvent.change(screen.getByLabelText('CNIE (ou passeport / carte de séjour)'), { target: { value: 'ZZ900009' } })
    fireEvent.change(screen.getByLabelText('E-mail professionnel'), { target: { value: 'nouvel@example.test' } })
    fireEvent.change(screen.getByLabelText('Prénom'), { target: { value: 'Nouvel' } })
    fireEvent.change(screen.getByLabelText('Nom'), { target: { value: 'Fictif' } })
    await waitFor(() => expect(within(screen.getByLabelText('Classe')).getByRole('option', { name: 'Classe Fictive' })).toBeInTheDocument())
    await waitFor(() => expect(within(screen.getByLabelText('Filtre Filière')).getByRole('option', { name: 'JAVA – Java' })).toBeInTheDocument())
    fireEvent.change(screen.getByLabelText('Campus'), { target: { value: '1' } })
    fireEvent.change(screen.getByLabelText('Promotion'), { target: { value: '2' } })
    fireEvent.change(screen.getByLabelText('Classe'), { target: { value: '3' } })
    expect(screen.getByText('Filière déduite : JAVA – Java')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Créer l’apprenant' }))

    expect(await screen.findByTestId('generated-password')).toHaveTextContent(PASSWORD)
    expect(calls.find((c) => c.method === 'POST' && c.url === '/api/v1/learners')?.body).toMatchObject({ cnie: 'ZZ900009', class_id: 3 })
    expect(cacheHas(queryClient, PASSWORD)).toBe(false)
    fireEvent.click(within(screen.getByRole('dialog', { name: 'Mot de passe généré' })).getByRole('button', { name: 'Fermer' }))
    expect(screen.queryByText(PASSWORD)).not.toBeInTheDocument()
  })

  it('erreur de champ : le message disparaît dès que le champ est corrigé', async () => {
    mockApi(ADMIN, [], (call) =>
      call.method === 'POST' && call.url === '/api/v1/learners'
        ? json(422, { message: 'Données invalides.', errors: { cnie: ['La CNIE doit contenir de 5 à 30 lettres ou chiffres, sans autre caractère.'] } })
        : undefined,
    )
    renderAt('/apprenants')

    fireEvent.click(await screen.findByRole('button', { name: 'Ajouter un apprenant' }))
    fireEvent.click(screen.getByRole('button', { name: 'Créer l’apprenant' }))
    expect(await screen.findByText('La CNIE doit contenir de 5 à 30 lettres ou chiffres, sans autre caractère.')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('CNIE (ou passeport / carte de séjour)'), { target: { value: 'ZZ900010' } })
    expect(screen.queryByText('La CNIE doit contenir de 5 à 30 lettres ou chiffres, sans autre caractère.')).not.toBeInTheDocument()
  })

  it('désactivation en masse : « N sélectionnés, dont M concernés », 409 puis nouvelle confirmation explicite', async () => {
    let attempts = 0
    const calls = mockApi(ADMIN, [adminRow(1, 'Alice'), adminRow(2, 'Bruno', { status: 'desactive' })], (call) => {
      if (call.url === '/api/v1/learners/selection/count') return json(200, { data: { selected: 2, eligible: 1 } })
      if (call.url === '/api/v1/learners/bulk-deactivate') {
        attempts += 1
        return attempts === 1
          ? json(409, { message: 'La sélection a changé : 0 apprenant(s) concerné(s) au lieu de 1. Confirmez à nouveau.', code: 'selection_changed', eligible: 0 })
          : json(200, { data: { changed: 0 } })
      }
      return undefined
    })
    renderAt('/apprenants')

    fireEvent.click(await screen.findByRole('checkbox', { name: 'Sélectionner Alice Fictif' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'Sélectionner Bruno Fictif' }))
    fireEvent.click(screen.getByRole('button', { name: 'Désactiver la sélection' }))
    expect(await screen.findByTestId('bulk-counts')).toHaveTextContent('2 sélectionné(s), dont 1 concerné(s).')
    expect(calls.find((c) => c.url === '/api/v1/learners/selection/count')?.body).toEqual({ ids: [1, 2], action: 'deactivate' })

    fireEvent.click(screen.getByRole('button', { name: 'Confirmer' }))
    expect(await screen.findByText(/La sélection a changé/)).toBeInTheDocument()
    expect(screen.getByTestId('bulk-counts')).toHaveTextContent('dont 0 concerné(s).')
    // Pas de relance automatique : un seul envoi, et 0 concerné rend la confirmation impossible.
    expect(attempts).toBe(1)
    expect(screen.getByRole('button', { name: 'Confirmer' })).toBeDisabled()
    expect(calls.find((c) => c.url === '/api/v1/learners/bulk-deactivate')?.body).toEqual({ ids: [1, 2], expected_eligible: 1 })
  })

  it('comptage arrivé après « Annuler » : ignoré, la confirmation ne se rouvre pas', async () => {
    let release: (r: Response) => void = () => undefined
    mockApi(ADMIN, [adminRow(1, 'Alice')], (call) =>
      call.url === '/api/v1/learners/selection/count' ? (new Promise<Response>((resolve) => (release = resolve)) as unknown as Response) : undefined,
    )
    renderAt('/apprenants')

    fireEvent.click(await screen.findByRole('checkbox', { name: 'Sélectionner Alice Fictif' }))
    fireEvent.click(screen.getByRole('button', { name: 'Désactiver la sélection' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Annuler' }))
    expect(screen.queryByRole('dialog', { name: 'Confirmer l’action en masse' })).not.toBeInTheDocument()

    release(json(200, { data: { selected: 1, eligible: 1 } }))
    await new Promise((r) => setTimeout(r, 20))
    expect(screen.queryByRole('dialog', { name: 'Confirmer l’action en masse' })).not.toBeInTheDocument()
    expect(screen.queryByTestId('bulk-counts')).not.toBeInTheDocument()
  })

  it('recherche en erreur : message et « Réessayer », puis la liste', async () => {
    let fail = true
    mockApi(ADMIN, [adminRow(1, 'Alice')], (call) =>
      call.url === '/api/v1/learners/search' && fail ? json(503, { message: 'Service momentanément indisponible.' }) : undefined,
    )
    renderAt('/apprenants')

    expect(await screen.findByText('Service momentanément indisponible.')).toBeInTheDocument()
    expect(screen.queryByText('Aucun apprenant.')).not.toBeInTheDocument()
    fail = false
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }))
    expect(await screen.findByText('Alice Fictif')).toBeInTheDocument()
    expect(screen.queryByText('Service momentanément indisponible.')).not.toBeInTheDocument()
  })

  it('« Sélectionner les N du filtre » envoie les filtres figés ; changer de filtre vide la sélection', async () => {
    const calls = mockApi(ADMIN, [adminRow(1, 'Alice'), adminRow(2, 'Bruno')], (call) =>
      call.url === '/api/v1/learners/selection/count' ? json(200, { data: { selected: 2, eligible: 2 } }) : undefined,
    )
    renderAt('/apprenants')

    fireEvent.click(await screen.findByRole('button', { name: 'Sélectionner les 2 apprenants du filtre' }))
    expect(screen.getByText('Tout le filtre : 2 apprenant(s) sélectionné(s).')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Désactiver la sélection' }))
    await waitFor(() => expect(calls.find((c) => c.url === '/api/v1/learners/selection/count')?.body).toEqual({ filters: { status: 'actif' }, action: 'deactivate' }))

    fireEvent.click(screen.getByRole('button', { name: 'Désactivés' }))
    expect(screen.queryByRole('region', { name: 'Sélection' })).not.toBeInTheDocument()
  })

  it('CME : CNIE visible, ni ajout ni désactivation ; compétences et ajout de compétence à la sélection', async () => {
    const calls = mockApi(CME, [learner(1, 'Alice', { cnie: 'ZZ900001' })], (call) =>
      call.method === 'PUT' && call.url === '/api/v1/learners/1/competences' ? json(200, { data: learner(1, 'Alice') }) : undefined,
    )
    renderAt('/apprenants')

    expect(await screen.findByText('Alice Fictif')).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'CNIE' })).toBeInTheDocument()
    expect(screen.getByText('ZZ900001')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ajouter un apprenant' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Modifier' })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('checkbox', { name: 'Sélectionner Alice Fictif' }))
    expect(screen.queryByRole('button', { name: 'Désactiver la sélection' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Ajouter une compétence à la sélection' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Compétences' }))
    fireEvent.click(await screen.findByRole('checkbox', { name: /SQL/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer les compétences' }))
    expect(await screen.findByText('Compétences enregistrées.')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'PUT')?.body).toEqual({ competence_ids: [7] })
  })

  it('Direction : lecture seule, sans sélection ni action', async () => {
    mockApi(DIRECTION, [learner(1, 'Alice')])
    renderAt('/apprenants')

    expect(await screen.findByText('Alice Fictif')).toBeInTheDocument()
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Sélectionner les/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('columnheader', { name: 'Actions' })).not.toBeInTheDocument()
  })
})
