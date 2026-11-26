import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import AppRoutes from '../AppRoutes'
import type { CurrentUser } from '../auth/types'
import type { CompanyImport, CompanyImportPreview, ImportSheets } from './importApi'

const ADMIN: CurrentUser = {
  id: 1, first_name: 'Admin', last_name: 'Fictif', email: 'admin@example.test', username: null,
  role: 'administrateur', role_label: 'Administrateur', permissions: ['companies.manage', 'events.manage', 'participants.manage'], home: '/pilotage',
}
const SECRET = 'MotDePasseFictif9'

function json(status: number, body?: unknown): Response {
  return new Response(body === undefined ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

const PREVIEW: CompanyImportPreview = {
  event: { id: 5, name: 'JobDating 2026/2027' },
  counts: { create: 1, existing: 1, duplicate: 0, reject: 0, unconfirmed: 3 },
  to_create: [{ line: 2, name: 'Acme Fictive', username: 'acme-fictive' }],
  signaled: [{ line: 4, reason: 'Entreprise déjà existante : ajoutez-la depuis l’événement', name: 'Beta Fictive' }],
  fingerprint: 'f'.repeat(64),
}

function report(extra: Partial<CompanyImport> = {}): CompanyImport {
  return {
    id: 9, filename: 'suivi.xlsx', status: 'termine', event: { id: 5, name: 'JobDating 2026/2027', deleted: false },
    counts: PREVIEW.counts, signaled: PREVIEW.signaled, has_credentials: true, credentials_deleted: false, error: null,
    author: 'Admin Fictif', created_at: '2026-11-25T10:00:00Z', finished_at: '2026-11-25T10:01:00Z', ...extra,
  }
}

const SHEETS: ImportSheets = {
  sheets: [{ company_id: 3, name: 'Acme Fictive', username: 'acme-fictive', password: SECRET, version: 1, url: 'http://localhost:5173', event: { id: 5, name: 'JobDating 2026/2027', date: '2026-12-25', location: 'Campus Fictif', floor: '2', table_label: 'C03' }, notice: null }],
  excluded: [{ name: 'Gamma Fictive', reason: 'Compte désactivé' }],
}

interface Call {
  method: string
  url: string
  body: Record<string, unknown> | undefined
}

function mockApi(handler: (call: Call) => Response | Promise<Response> | undefined) {
  const calls: Call[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const raw = init?.body
      const call: Call = {
        method: init?.method ?? 'GET',
        url: String(input),
        body: raw instanceof FormData ? Object.fromEntries([...raw.entries()].map(([k, v]) => [k, typeof v === 'string' ? v : 'fichier'])) : raw ? JSON.parse(String(raw)) : undefined,
      }
      calls.push(call)
      if (call.url === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
      if (call.url === '/api/v1/me') return json(200, { data: ADMIN })
      if (call.url === '/api/v1/company-imports/expected') return json(200, { data: { sheet: 'Entreprises Invitées', name: 'Entreprises', confirmation: 'Confirmation par mail' } })
      if (call.url === '/api/v1/event-options') return json(200, { data: { current_id: 5, events: [{ id: 5, name: 'JobDating 2026/2027', date: '2026-12-25', is_current: true }] } })
      const response = await handler(call)
      if (response) return response
      if (call.method === 'GET' && call.url.startsWith('/api/v1/company-imports')) return json(200, { data: [], meta: { current_page: 1, last_page: 1 } })
      throw new Error(`Appel inattendu : ${call.method} ${call.url}`)
    }),
  )
  return calls
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/entreprises/import']}>
        <AppRoutes />
      </MemoryRouter>
    </QueryClientProvider>,
  )
  return queryClient
}

async function chooseFile() {
  const input = (await screen.findByLabelText('Classeur (.xlsx)')) as HTMLInputElement
  await waitFor(() => expect(screen.getByLabelText('Événement')).toHaveValue('5'))
  fireEvent.change(input, { target: { files: [new File(['x'], 'suivi.xlsx')] } })
}

describe('Import des entreprises', () => {
  it('erreur d’aperçu, nouvel essai, confirmation avec la même clé après une erreur réseau', async () => {
    let previews = 0
    let confirms = 0
    const calls = mockApi((call) => {
      if (call.url === '/api/v1/company-imports/preview') {
        previews += 1
        return previews === 1 ? json(422, { message: 'Feuille « Entreprises Invitées » introuvable dans le classeur.', errors: { file: ['Feuille « Entreprises Invitées » introuvable dans le classeur.'] } }) : json(200, { data: PREVIEW })
      }
      if (call.method === 'POST' && call.url === '/api/v1/company-imports') {
        confirms += 1
        if (confirms === 1) throw new TypeError('Failed to fetch')
        return json(201, { data: report() })
      }
      return undefined
    })
    renderPage()

    await chooseFile()
    fireEvent.click(screen.getByRole('button', { name: 'Voir l’aperçu' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('introuvable')

    fireEvent.click(screen.getByRole('button', { name: 'Voir l’aperçu' }))
    expect(await screen.findByText('acme-fictive')).toBeInTheDocument()
    expect(screen.getByText(/Beta Fictive/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Confirmer l’import (1 entreprises)' }))
    expect(await screen.findByRole('alert')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Confirmer l’import (1 entreprises)' }))
    expect(await screen.findByText(/Import terminé pour JobDating 2026\/2027/)).toBeInTheDocument()

    const keys = calls.filter((c) => c.method === 'POST' && c.url === '/api/v1/company-imports').map((c) => c.body?.confirmation_key)
    expect(keys).toHaveLength(2)
    expect(keys[0]).toBe(keys[1])
    expect(calls.find((c) => c.url === '/api/v1/company-imports/preview')?.body).toMatchObject({ event_id: '5', file: 'fichier' })
  })

  it('aperçu changé (409) : nouvel aperçu automatique, pas de double clic', async () => {
    let finish: (r: Response) => void = () => undefined
    let previews = 0
    const calls = mockApi((call) => {
      if (call.url === '/api/v1/company-imports/preview') {
        previews += 1
        return json(200, { data: { ...PREVIEW, fingerprint: String(previews).repeat(64) } })
      }
      if (call.method === 'POST' && call.url === '/api/v1/company-imports') {
        return new Promise<Response>((resolve) => {
          finish = resolve
        })
      }
      return undefined
    })
    renderPage()

    await chooseFile()
    fireEvent.click(screen.getByRole('button', { name: 'Voir l’aperçu' }))
    const confirm = await screen.findByRole('button', { name: 'Confirmer l’import (1 entreprises)' })
    fireEvent.click(confirm)
    fireEvent.click(confirm)
    await waitFor(() => expect(calls.filter((c) => c.method === 'POST' && c.url === '/api/v1/company-imports')).toHaveLength(1))

    finish(json(409, { message: 'L’aperçu a changé : vérifiez-le et confirmez à nouveau.', code: 'preview_changed' }))
    await waitFor(() => expect(previews).toBe(2))
    expect(await screen.findByText(/L’aperçu a changé/)).toBeInTheDocument()
  })

  it('fiches : affichées hors cache, effacées en changeant d’import ; réponse tardive ignorée', async () => {
    let finish: (r: Response) => void = () => undefined
    let sheetCalls = 0
    mockApi((call) => {
      if (call.method === 'GET' && call.url === '/api/v1/company-imports') return json(200, { data: [report()], meta: { current_page: 1, last_page: 1 } })
      if (call.url === '/api/v1/company-imports/9') return json(200, { data: report() })
      if (call.url === '/api/v1/company-imports/9/credentials') {
        sheetCalls += 1
        if (sheetCalls === 1) return json(200, { data: SHEETS })
        return new Promise<Response>((resolve) => {
          finish = resolve
        })
      }
      if (call.url === '/api/v1/company-imports/9/credentials-printed') return json(200, { data: { marked: 1, skipped: 0 } })
      return undefined
    })
    const queryClient = renderPage()

    fireEvent.click(await screen.findByRole('button', { name: 'Voir le rapport de l’import 9' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Afficher les fiches' }))
    expect(await screen.findByText(SECRET)).toBeInTheDocument()
    expect(screen.getByText('Étage 2, table C03')).toBeInTheDocument()
    expect(screen.getByText(/Gamma Fictive : Compte désactivé/)).toBeInTheDocument()
    expect(JSON.stringify(queryClient.getQueryCache().getAll().map((q) => q.state.data ?? null))).not.toContain(SECRET)

    fireEvent.click(screen.getByRole('button', { name: 'J’ai imprimé les fiches' }))
    expect(await screen.findByText('1 fiche(s) marquée(s) comme imprimée(s).')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Masquer les fiches' }))
    expect(screen.queryByText(SECRET)).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Afficher les fiches' }))
    fireEvent.click(screen.getByRole('button', { name: 'Nouvel import' }))
    finish(json(200, { data: SHEETS }))
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(screen.queryByText(SECRET)).not.toBeInTheDocument()
  })

  it('erreur d’affichage des fiches puis nouvel essai', async () => {
    let fail = true
    mockApi((call) => {
      if (call.method === 'GET' && call.url === '/api/v1/company-imports') return json(200, { data: [report()], meta: { current_page: 1, last_page: 1 } })
      if (call.url === '/api/v1/company-imports/9') return json(200, { data: report() })
      if (call.url === '/api/v1/company-imports/9/credentials') return fail ? json(503, { message: 'Service momentanément indisponible.' }) : json(200, { data: SHEETS })
      return undefined
    })
    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: 'Voir le rapport de l’import 9' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Afficher les fiches' }))
    expect(await screen.findByText('Service momentanément indisponible.')).toBeInTheDocument()
    fail = false
    fireEvent.click(screen.getByRole('button', { name: 'Afficher les fiches' }))
    expect(await screen.findByText(SECRET)).toBeInTheDocument()
    expect(screen.queryByText('Service momentanément indisponible.')).not.toBeInTheDocument()
  })

  // Revue Codex T-64, constat 1.
  it('une réponse tardive ne réaffiche jamais les fiches après masquage ou suppression', async () => {
    const pendingSheets: ((r: Response) => void)[] = []
    let deleted = false
    mockApi((call) => {
      if (call.method === 'GET' && call.url === '/api/v1/company-imports') return json(200, { data: [report()], meta: { current_page: 1, last_page: 1 } })
      if (call.url === '/api/v1/company-imports/9') return json(200, { data: report({ has_credentials: !deleted, credentials_deleted: deleted }) })
      if (call.method === 'GET' && call.url === '/api/v1/company-imports/9/credentials') {
        return new Promise<Response>((resolve) => {
          pendingSheets.push(resolve)
        })
      }
      if (call.method === 'DELETE' && call.url === '/api/v1/company-imports/9/credentials') {
        deleted = true
        return new Response(null, { status: 204 })
      }
      return undefined
    })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    renderPage()

    // 1. Double clic : une seule demande ; masquage puis réponse tardive.
    fireEvent.click(await screen.findByRole('button', { name: 'Voir le rapport de l’import 9' }))
    const show = await screen.findByRole('button', { name: 'Afficher les fiches' })
    fireEvent.click(show)
    fireEvent.click(screen.getByRole('button', { name: 'Chargement des fiches…' }))
    expect(pendingSheets).toHaveLength(1)
    pendingSheets[0](json(200, { data: SHEETS }))
    expect(await screen.findByText(SECRET)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Masquer les fiches' }))
    fireEvent.click(screen.getByRole('button', { name: 'Afficher les fiches' }))
    fireEvent.click(screen.getByRole('button', { name: 'Supprimer les mots de passe' }))
    expect(await screen.findByText('Mots de passe de l’import supprimés.')).toBeInTheDocument()

    // 2. La demande partie avant la suppression répond après : rien ne réapparaît.
    pendingSheets[1](json(200, { data: SHEETS }))
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(screen.queryByText(SECRET)).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Afficher les fiches' })).not.toBeInTheDocument()
  })

  // Contre-revue Codex T-64, N1 et N2.
  it('suppression en cours : double clic ignoré, fiches bloquées même après réouverture du même import', async () => {
    let finishDelete: (r: Response) => void = () => undefined
    let deleted = false
    const calls = mockApi((call) => {
      if (call.method === 'GET' && call.url === '/api/v1/company-imports') return json(200, { data: [report()], meta: { current_page: 1, last_page: 1 } })
      if (call.url === '/api/v1/company-imports/9') return json(200, { data: report({ has_credentials: !deleted, credentials_deleted: deleted }) })
      if (call.method === 'GET' && call.url === '/api/v1/company-imports/9/credentials') return json(200, { data: SHEETS })
      if (call.method === 'DELETE' && call.url === '/api/v1/company-imports/9/credentials') {
        return new Promise<Response>((resolve) => {
          finishDelete = (r) => {
            deleted = true
            resolve(r)
          }
        })
      }
      return undefined
    })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: 'Voir le rapport de l’import 9' }))
    const remove = await screen.findByRole('button', { name: 'Supprimer les mots de passe' })
    // N2 : double clic, une seule suppression envoyée, bouton désactivé.
    fireEvent.click(remove)
    fireEvent.click(remove)
    await waitFor(() => expect(calls.filter((c) => c.method === 'DELETE')).toHaveLength(1))
    expect(screen.getByRole('button', { name: 'Suppression des mots de passe…' })).toBeDisabled()

    // N1 : réouverture du même import pendant la suppression, fiches impossibles à charger.
    fireEvent.click(screen.getByRole('button', { name: 'Voir le rapport de l’import 9' }))
    const show = await screen.findByRole('button', { name: 'Afficher les fiches' })
    expect(show).toBeDisabled()
    fireEvent.click(show)
    expect(calls.filter((c) => c.method === 'GET' && c.url === '/api/v1/company-imports/9/credentials')).toHaveLength(0)

    finishDelete(new Response(null, { status: 204 }))
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Afficher les fiches' })).not.toBeInTheDocument())
    expect(screen.queryByText(SECRET)).not.toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  // Vérification Codex T-64, V1.
  it('suppression réussie puis relecture en échec : les actions des fiches disparaissent quand même', async () => {
    let deleted = false
    mockApi((call) => {
      if (call.method === 'GET' && call.url === '/api/v1/company-imports') return json(200, { data: [report()], meta: { current_page: 1, last_page: 1 } })
      if (call.url === '/api/v1/company-imports/9') return deleted ? json(503, { message: 'Service momentanément indisponible.' }) : json(200, { data: report() })
      if (call.method === 'DELETE' && call.url === '/api/v1/company-imports/9/credentials') {
        deleted = true
        return new Response(null, { status: 204 })
      }
      return undefined
    })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: 'Voir le rapport de l’import 9' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Supprimer les mots de passe' }))
    expect(await screen.findByText('Mots de passe de l’import supprimés.')).toBeInTheDocument()
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(screen.queryByRole('button', { name: 'Afficher les fiches' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Supprimer les mots de passe|Suppression des mots de passe/ })).not.toBeInTheDocument()
  })

  // Revue Codex T-64, constat 3.
  it('historique : « créées » seulement pour un import terminé', async () => {
    mockApi((call) =>
      call.method === 'GET' && call.url === '/api/v1/company-imports'
        ? json(200, {
            data: [report({ id: 1, status: 'echec', has_credentials: false }), report({ id: 2, status: 'en_attente', has_credentials: false }), report({ id: 3 })],
            meta: { current_page: 1, last_page: 1 },
          })
        : undefined,
    )
    renderPage()

    expect(await screen.findByText(/Échec · 1 prévues, aucune créée/)).toBeInTheDocument()
    expect(screen.getByText(/En attente · 1 à créer/)).toBeInTheDocument()
    expect(screen.getByText(/Terminé · 1 créées/)).toBeInTheDocument()
  })
})
