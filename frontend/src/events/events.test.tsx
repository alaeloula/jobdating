import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import AppRoutes from '../AppRoutes'
import type { CurrentUser, RoleName } from '../auth/types'
import { schoolYear, type EventItem, type Participant } from './api'

const ADMIN_PERMISSIONS = ['events.manage', 'participants.manage', 'locations.manage', 'companies.manage', 'companies.profiles.manage']

function user(role: RoleName, permissions: string[], home: string): CurrentUser {
  return { id: 1, first_name: 'Compte', last_name: 'Fictif', email: 'compte@example.test', username: null, role, role_label: role, permissions, home }
}

const ADMIN = user('administrateur', ADMIN_PERMISSIONS, '/pilotage')
const CME = user('cme', ['locations.manage', 'companies.profiles.manage'], '/pilotage')

function event(id: number, extra: Partial<EventItem> = {}): EventItem {
  return {
    id, name: 'JobDating', school_year: '2026/2027', full_name: 'JobDating 2026/2027', date: '2026-12-15', created_on: '2026-11-23',
    location: 'Campus Fictif', latitude: null, longitude: null, description: null, is_current: false, archived: false, status: 'a_venir',
    passages: 0, date_locked: false, ...extra,
  }
}

function participant(id: number, name: string, extra: Partial<Participant> = {}): Participant {
  return { id, name, sector: null, status: 'actif', floor: null, table_label: null, passages: 0, username: `user-${id}`, credentials_printed: false, can_remove: true, ...extra }
}

function json(status: number, body?: unknown): Response {
  return new Response(body === undefined ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

interface Call {
  method: string
  url: string
  body: unknown
}

function mockApi(me: CurrentUser, handler: (call: Call) => Response | Promise<Response> | undefined) {
  const calls: Call[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const call: Call = { method: init?.method ?? 'GET', url: String(input), body: init?.body ? JSON.parse(String(init.body)) : undefined }
      calls.push(call)
      if (call.url === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
      if (call.url === '/api/v1/me') return json(200, { data: me })
      const response = await handler(call)
      if (response) return response
      if (call.url.startsWith('/api/v1/companies')) return json(200, { data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
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

const META = (rows: Participant[]) => ({
  total: rows.length,
  without_location: rows.filter((r) => !r.floor && !r.table_label).length,
  not_printed: rows.filter((r) => !r.credentials_printed).length,
})

describe('Année scolaire', () => {
  it.each([
    ['2026-08-31', '2025/2026'],
    ['2026-09-01', '2026/2027'],
    ['2027-08-31', '2026/2027'],
    ['2027-09-01', '2027/2028'],
  ])('%s donne %s', (date, year) => {
    expect(schoolYear(date)).toBe(year)
  })
})

describe('Événement et programme', () => {
  it('création : aperçu du nom, erreur corrigée, nouvel essai, rappel affiché', async () => {
    let list: EventItem[] = []
    let attempts = 0
    const calls = mockApi(ADMIN, (call) => {
      if (call.method === 'GET' && call.url === '/api/v1/events') return json(200, { data: list })
      if (call.method === 'POST' && call.url === '/api/v1/events') {
        attempts += 1
        if (attempts === 1) return json(422, { message: 'Données invalides', errors: { date: ['Un événement existe déjà pour l’année scolaire 2026/2027.'] } })
        list = [event(1)]
        return json(201, { data: list[0], reminder: 'Pensez à désactiver les apprenants qui ne participent plus (ADM-03) et à ajouter les entreprises participantes.' })
      }
      return undefined
    })
    renderAt('/evenements')

    const date = await screen.findByLabelText('Date (une journée)')
    fireEvent.change(date, { target: { value: '2026-12-15' } })
    fireEvent.change(screen.getByLabelText('Lieu'), { target: { value: 'Campus Fictif' } })
    expect(screen.getByTestId('event-full-name')).toHaveTextContent('JobDating 2026/2027')

    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))
    expect(await screen.findByText(/existe déjà pour l’année scolaire/)).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Date (une journée)'), { target: { value: '2027-12-15' } })
    expect(screen.getByTestId('event-full-name')).toHaveTextContent('JobDating 2027/2028')
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByText(/Pensez à désactiver/)).toBeInTheDocument()
    expect(calls.filter((c) => c.method === 'POST' && c.url === '/api/v1/events')).toHaveLength(2)
  })

  it('désignation : rapport affiché, double clic envoyé une seule fois', async () => {
    let list = [event(1)]
    let finish: (response: Response) => void = () => undefined
    const calls = mockApi(ADMIN, (call) => {
      if (call.method === 'GET' && call.url === '/api/v1/events') return json(200, { data: list })
      if (call.url === '/api/v1/events/1/current') {
        return new Promise<Response>((resolve) => {
          finish = resolve
        })
      }
      return undefined
    })
    renderAt('/evenements')

    const designate = await screen.findByRole('button', { name: 'Désigner comme courant' })
    fireEvent.click(designate)
    fireEvent.click(designate)
    await waitFor(() => expect(calls.filter((c) => c.url === '/api/v1/events/1/current')).toHaveLength(1))

    list = [event(1, { is_current: true })]
    finish(json(200, { data: list[0], report: { enrolled: 12, already: 0, ignored_deactivated: 2, ignored_removed: 1 } }))
    expect(await screen.findByText(/12 apprenant\(s\) inscrit\(s\)/)).toBeInTheDocument()
    expect(await screen.findByRole('button', { name: 'Retirer le statut courant' })).toBeInTheDocument()
    expect(calls.filter((c) => c.url === '/api/v1/events/1/current')).toHaveLength(1)
  })

  it('suppression refusée : message du serveur, l’archivage reste possible', async () => {
    const calls = mockApi(ADMIN, (call) => {
      if (call.method === 'GET' && call.url === '/api/v1/events') return json(200, { data: [event(1)] })
      if (call.method === 'DELETE' && call.url === '/api/v1/events/1') {
        return json(409, { message: 'Cet événement contient des passages : il ne peut pas être supprimé, vous pouvez l’archiver.', code: 'event_has_passages' })
      }
      return undefined
    })
    renderAt('/evenements')

    fireEvent.click(await screen.findByRole('button', { name: 'Supprimer' }))
    fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Confirmer la suppression' }))
    expect(await screen.findByText(/vous pouvez l’archiver/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Archiver' })).toBeEnabled()
    expect(calls.filter((c) => c.method === 'DELETE')).toHaveLength(1)
  })

  it('un événement archivé ne propose pas la suppression', async () => {
    mockApi(ADMIN, (call) => (call.url === '/api/v1/events' ? json(200, { data: [event(1, { archived: true })] }) : undefined))
    renderAt('/evenements')

    expect(await screen.findByRole('button', { name: 'Désarchiver' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Supprimer' })).not.toBeInTheDocument()
  })

  it('erreur de chargement puis « Réessayer »', async () => {
    let fail = true
    mockApi(ADMIN, (call) => {
      if (call.url === '/api/v1/events') return fail ? json(503, { message: 'Indisponible' }) : json(200, { data: [event(1)] })
      return undefined
    })
    renderAt('/evenements')

    expect(await screen.findByText('Les événements n’ont pas pu être chargés.')).toBeInTheDocument()
    fail = false
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }))
    expect(await screen.findByLabelText('Lieu')).toHaveValue('Campus Fictif')
  })
})

describe('Entreprises participantes', () => {
  function participantsApi(rows: Participant[], handler: (call: Call) => Response | Promise<Response> | undefined = () => undefined) {
    return mockApi(ADMIN, (call) => {
      const custom = handler(call)
      if (custom) return custom
      if (call.method === 'GET' && call.url === '/api/v1/events') return json(200, { data: [event(1, { is_current: true })] })
      if (call.method === 'GET' && call.url.startsWith('/api/v1/events/1/companies')) return json(200, { data: rows, meta: META(rows) })
      return undefined
    })
  }

  it('retrait refusé : message du serveur ; « Retrait impossible » pour une entreprise avec passage', async () => {
    const calls = participantsApi([participant(1, 'Alpha'), participant(2, 'Beta', { can_remove: false, passages: 3 })], (call) => {
      if (call.method === 'DELETE') return json(409, { message: 'Ce participant a déjà des passages dans cet événement, il ne peut pas être retiré', code: 'participant_has_passages' })
      return undefined
    })
    renderAt('/entreprises')

    expect(await screen.findByRole('button', { name: 'Retrait impossible' })).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: 'Retirer' }))
    fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Confirmer le retrait' }))

    expect(await screen.findByText(/déjà des passages/)).toBeInTheDocument()
    expect(calls.filter((c) => c.method === 'DELETE').map((c) => c.url)).toEqual(['/api/v1/events/1/companies/1'])
  })

  it('ajout en masse : rapport des ajouts', async () => {
    const calls = participantsApi([], (call) => {
      if (call.url === '/api/v1/events/1/available-companies') return json(200, { data: [{ id: 7, name: 'Gamma', sector: null }] })
      if (call.method === 'POST' && call.url === '/api/v1/events/1/companies') return json(200, { report: { added: 1, already: 0, ignored: 0 } })
      return undefined
    })
    renderAt('/entreprises')

    fireEvent.click(await screen.findByRole('button', { name: 'Ajouter des entreprises existantes' }))
    fireEvent.click(await screen.findByLabelText(/Gamma/))
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter 1 entreprise(s)' }))

    expect(await screen.findByText(/1 entreprise\(s\) ajoutée\(s\)/)).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'POST' && c.url === '/api/v1/events/1/companies')?.body).toEqual({ company_ids: [7] })
  })

  it('changement d’événement pendant une action : la réponse tardive n’est pas affichée', async () => {
    let finish: (response: Response) => void = () => undefined
    mockApi(ADMIN, (call) => {
      if (call.method === 'GET' && call.url === '/api/v1/events') {
        return json(200, { data: [event(1, { is_current: true }), event(2, { full_name: 'JobDating 2027/2028', school_year: '2027/2028', date: '2027-12-15' })] })
      }
      if (call.method === 'GET' && call.url === '/api/v1/events/1/companies') return json(200, { data: [participant(1, 'Alpha')], meta: META([participant(1, 'Alpha')]) })
      if (call.method === 'GET' && call.url === '/api/v1/events/2/companies') return json(200, { data: [participant(2, 'Beta')], meta: META([participant(2, 'Beta')]) })
      if (call.method === 'DELETE') {
        return new Promise<Response>((resolve) => {
          finish = resolve
        })
      }
      return undefined
    })
    renderAt('/entreprises')

    await screen.findByText('Alpha')
    fireEvent.click(screen.getByRole('button', { name: 'Retirer' }))
    fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Confirmer le retrait' }))
    fireEvent.change(screen.getByLabelText('Événement'), { target: { value: '2' } })
    expect(await screen.findByText('Beta')).toBeInTheDocument()

    finish(json(409, { message: 'Réponse tardive de l’ancien événement', code: 'participant_has_passages' }))
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(screen.queryByText('Réponse tardive de l’ancien événement')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Retirer' })).toBeEnabled()
  })

  // Revue Codex T-14, constat 1.
  it('changement d’événement : aucune ligne de l’ancien événement n’est modifiable', async () => {
    let finish: (response: Response) => void = () => undefined
    const first = [participant(7, 'Alpha', { floor: '1', table_label: 'A1' })]
    const second = [participant(7, 'Alpha', { floor: '9', table_label: 'Z9' })]
    const calls = mockApi(ADMIN, (call) => {
      if (call.method === 'GET' && call.url === '/api/v1/events') {
        return json(200, { data: [event(1, { is_current: true }), event(2, { full_name: 'JobDating 2027/2028', school_year: '2027/2028', date: '2027-12-15' })] })
      }
      if (call.url === '/api/v1/events/1/companies') return json(200, { data: first, meta: META(first) })
      if (call.url === '/api/v1/events/2/companies') {
        return new Promise<Response>((resolve) => {
          finish = resolve
        })
      }
      return undefined
    })
    renderAt('/entreprises')

    fireEvent.click(await screen.findByRole('button', { name: 'Emplacement' }))
    expect(screen.getByLabelText('Étage de Alpha')).toHaveValue('1')
    fireEvent.change(screen.getByLabelText('Événement'), { target: { value: '2' } })

    await waitFor(() => expect(screen.queryByText('Alpha')).not.toBeInTheDocument())
    expect(screen.queryByLabelText('Étage de Alpha')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Emplacement' })).not.toBeInTheDocument()

    finish(json(200, { data: second, meta: META(second) }))
    fireEvent.click(await screen.findByRole('button', { name: 'Emplacement' }))
    expect(screen.getByLabelText('Étage de Alpha')).toHaveValue('9')
    expect(calls.some((c) => c.method === 'PATCH')).toBe(false)
  })

  // Revue Codex T-14, constat 5.
  it('succès tardif de l’ancien événement : la sélection du nouveau est conservée', async () => {
    let finish: (response: Response) => void = () => undefined
    mockApi(ADMIN, (call) => {
      if (call.method === 'GET' && call.url === '/api/v1/events') {
        return json(200, { data: [event(1, { is_current: true }), event(2, { full_name: 'JobDating 2027/2028', school_year: '2027/2028', date: '2027-12-15' })] })
      }
      if (call.method === 'GET' && /\/events\/\d\/companies/.test(call.url)) return json(200, { data: [], meta: META([]) })
      if (call.url === '/api/v1/events/1/available-companies') return json(200, { data: [{ id: 7, name: 'Gamma', sector: null }] })
      if (call.url === '/api/v1/events/2/available-companies') return json(200, { data: [{ id: 8, name: 'Delta', sector: null }] })
      if (call.method === 'POST' && call.url === '/api/v1/events/1/companies') {
        return new Promise<Response>((resolve) => {
          finish = resolve
        })
      }
      return undefined
    })
    renderAt('/entreprises')

    fireEvent.click(await screen.findByRole('button', { name: 'Ajouter des entreprises existantes' }))
    fireEvent.click(await screen.findByLabelText(/Gamma/))
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter 1 entreprise(s)' }))

    fireEvent.change(screen.getByLabelText('Événement'), { target: { value: '2' } })
    fireEvent.click(await screen.findByRole('button', { name: 'Ajouter des entreprises existantes' }))
    const delta = await screen.findByLabelText(/Delta/)
    fireEvent.click(delta)

    finish(json(200, { report: { added: 1, already: 0, ignored: 0 } }))
    await new Promise((resolve) => setTimeout(resolve, 30))
    expect(screen.getByLabelText(/Delta/)).toBeChecked()
    expect(screen.getByRole('button', { name: 'Ajouter 1 entreprise(s)' })).toBeEnabled()
    expect(screen.queryByText(/1 entreprise\(s\) ajoutée/)).not.toBeInTheDocument()
  })

  it('réinitialisation depuis l’événement : la fiche reçoit cet événement', async () => {
    const calls = participantsApi([participant(1, 'Alpha', { floor: '1', table_label: 'B12' })], (call) => {
      if (call.url === '/api/v1/companies/1/reset-password') {
        return json(200, {
          data: { id: 1, name: 'Alpha', sector: null, status: 'actif', credentials_version: 2, filieres: [], competences: [], recruiters: [] },
          credentials: {
            username: 'alpha', password: 'Secret1Abcdefgh', version: 2, url: 'x',
            event: { id: 1, name: 'JobDating 2026/2027', date: '2026-12-15', location: 'Campus Fictif', floor: '1', table_label: 'B12' },
          },
        })
      }
      return undefined
    })
    renderAt('/entreprises')

    fireEvent.click(await screen.findByRole('button', { name: 'Réinitialiser le mot de passe' }))
    fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Confirmer' }))

    expect(await screen.findByTestId('sheet-event')).toHaveTextContent('Étage 1, table B12')
    expect(calls.find((c) => c.url === '/api/v1/companies/1/reset-password')?.body).toEqual({ event_id: 1 })
  })
})

describe('Emplacements (CME)', () => {
  it('erreur d’enregistrement, saisie conservée, nouvel essai réussi', async () => {
    let attempts = 0
    const rows = [participant(1, 'Alpha', { username: undefined, credentials_printed: undefined, can_remove: undefined })]
    const calls = mockApi(CME, (call) => {
      if (call.url === '/api/v1/events/current/summary') return json(200, { data: { id: 1, name: 'JobDating 2026/2027', date: '2026-12-15', location: 'L', status: 'a_venir' } })
      if (call.method === 'GET' && call.url.startsWith('/api/v1/events/1/companies')) return json(200, { data: attempts >= 2 ? [] : rows, meta: { total: 1, without_location: attempts >= 2 ? 0 : 1, not_printed: null } })
      if (call.method === 'PATCH') {
        attempts += 1
        return attempts === 1 ? json(503, { message: 'Service momentanément indisponible.' }) : json(200, { data: { id: 1, floor: '2', table_label: 'C03' } })
      }
      return undefined
    })
    renderAt('/emplacements')

    fireEvent.change(await screen.findByLabelText('Étage de Alpha'), { target: { value: '2' } })
    fireEvent.change(screen.getByLabelText('Table de Alpha'), { target: { value: 'C03' } })
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer l’emplacement de Alpha' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Service momentanément indisponible.')
    expect(screen.getByLabelText('Table de Alpha')).toHaveValue('C03')

    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer l’emplacement de Alpha' }))
    expect(await screen.findByText('Emplacement de Alpha enregistré.')).toBeInTheDocument()
    expect(calls.filter((c) => c.method === 'PATCH').at(-1)?.body).toEqual({ floor: '2', table_label: 'C03' })
  })

  // Revue Codex T-14, constat 1 : le brouillon d'un événement ne passe pas au suivant.
  it('changement d’événement courant : le brouillon est effacé', async () => {
    let currentId = 1
    const rows = [participant(1, 'Alpha', { username: undefined, credentials_printed: undefined, can_remove: undefined })]
    mockApi(CME, (call) => {
      if (call.url === '/api/v1/events/current/summary') return json(200, { data: { id: currentId, name: `JobDating ${currentId}`, date: '2026-12-15', location: 'L', status: 'a_venir' } })
      if (call.method === 'GET' && /\/events\/\d\/companies/.test(call.url)) return json(200, { data: rows, meta: { total: 1, without_location: 1, not_printed: null } })
      return undefined
    })
    const client = renderAt('/emplacements')

    fireEvent.change(await screen.findByLabelText('Table de Alpha'), { target: { value: 'BROUILLON' } })
    currentId = 2
    await client.invalidateQueries({ queryKey: ['events'] })

    await screen.findByText(/JobDating 2 ·/)
    await waitFor(() => expect(screen.getByLabelText('Table de Alpha')).toHaveValue(''))
  })

  it('sans événement courant : message explicatif', async () => {
    mockApi(CME, (call) => (call.url === '/api/v1/events/current/summary' ? json(200, { data: null }) : undefined))
    renderAt('/emplacements')
    expect(await screen.findByText(/Aucun événement courant/)).toBeInTheDocument()
  })
})

describe('Participants', () => {
  const COMPANY = user('entreprise', [], '/entreprise')
  const LEARNER = user('apprenant', [], '/ma-journee')

  it('entreprise : événement courant et emplacement, sans sélecteur', async () => {
    mockApi(COMPANY, (call) =>
      call.url === '/api/v1/me/company-event'
        ? json(200, { data: { id: 1, name: 'JobDating 2026/2027', date: '2026-12-15', location: 'Campus Fictif', floor: '1', table_label: 'B12' } })
        : undefined,
    )
    renderAt('/entreprise')

    expect(await screen.findByText(/JobDating 2026\/2027 · 15\/12\/2026 · Campus Fictif · Votre emplacement : Étage 1, table B12/)).toBeInTheDocument()
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument()
  })

  it('entreprise sans participation à l’événement courant : message', async () => {
    mockApi(COMPANY, (call) => (call.url === '/api/v1/me/company-event' ? json(200, { data: null }) : undefined))
    renderAt('/entreprise')
    expect(await screen.findByText(/ne participe pas à l’événement de cette année/)).toBeInTheDocument()
  })

  it('apprenant : événement et entreprises présentes', async () => {
    mockApi(LEARNER, (call) =>
      call.url === '/api/v1/me/learner-event'
        ? json(200, {
            data: {
              event: { id: 1, name: 'JobDating 2026/2027', date: '2026-12-15', location: 'Campus Fictif', description: null, status: 'en_cours' },
              companies: [{ id: 1, name: 'Alpha', sector: 'Banque', floor: '1', table_label: 'B12' }],
            },
          })
        : undefined,
    )
    renderAt('/ma-journee')

    expect(await screen.findByText('JobDating 2026/2027')).toBeInTheDocument()
    expect(screen.getByText('Aujourd’hui')).toBeInTheDocument()
    expect(screen.getByText('Étage 1 · B12')).toBeInTheDocument()
  })

  it('apprenant : erreur puis « Réessayer », puis état vide', async () => {
    let fail = true
    mockApi(LEARNER, (call) => (call.url === '/api/v1/me/learner-event' ? (fail ? json(503, { message: 'Indisponible' }) : json(200, { data: null })) : undefined))
    renderAt('/ma-journee')

    expect(await screen.findByText('Votre journée n’a pas pu être chargée.')).toBeInTheDocument()
    fail = false
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }))
    expect(await screen.findByText('Aucun événement ne vous concerne pour le moment.')).toBeInTheDocument()
  })
})
