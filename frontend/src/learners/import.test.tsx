import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import AppRoutes from '../AppRoutes'
import type { CurrentUser } from '../auth/types'
import type { ImportPreview, LearnerImport } from './importApi'

const ADMIN: CurrentUser = {
  id: 1, first_name: 'Admin', last_name: 'Fictif', email: 'admin@example.test', username: null,
  role: 'administrateur', role_label: 'Administrateur', permissions: ['learners.view', 'learners.import'], home: '/pilotage',
}

const PREVIEW: ImportPreview = {
  campus: 'Campus Fictif', promotion: 'Promotion Fictive', lines: 3,
  counts: { create: 2, update: 0, unchanged: 0, reject: 1 },
  classes: [{ class: 'AI LEEK', create: 1, update: 0, unchanged: 0 }, { class: 'JAIWAR', create: 1, update: 0, unchanged: 0 }],
  rejections: [{ line: 4, reason: 'CNIE manquante' }], fingerprint: 'f'.repeat(64),
}

function imported(status: LearnerImport['status'], extra: Partial<LearnerImport> = {}): LearnerImport {
  return {
    id: 7, filename: 'export.csv', status, counts: PREVIEW.counts, classes: PREVIEW.classes, rejections: PREVIEW.rejections,
    has_passwords: status === 'termine', passwords_deleted: false, error: null, author: 'Admin Fictif',
    created_at: '2026-11-20T10:00:00+00:00', finished_at: null, ...extra,
  }
}

interface Call {
  method: string
  url: string
  body: Record<string, FormDataEntryValue> | undefined
}

function json(status: number, body?: unknown): Response {
  return new Response(body === undefined ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

function mockApi(handler: (call: Call) => Response | Promise<Response> | undefined) {
  const calls: Call[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const raw = init?.body
      const call: Call = { method: init?.method ?? 'GET', url: String(input), body: raw instanceof FormData ? Object.fromEntries(raw.entries()) : undefined }
      calls.push(call)
      if (call.url === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
      if (call.url === '/api/v1/me') return json(200, { data: ADMIN })
      const custom = await handler(call)
      if (custom) return custom
      if (call.url.startsWith('/api/v1/referentials/campus/options')) return json(200, { data: [{ id: 1, label: 'Campus Fictif', active: true }] })
      if (call.url.startsWith('/api/v1/referentials/promotions/options')) return json(200, { data: [{ id: 2, label: 'Promotion Fictive', active: true }] })
      if (call.url === '/api/v1/learner-imports') return json(200, { data: [], meta: { current_page: 1, last_page: 1 } })
      throw new Error(`Appel inattendu : ${call.method} ${call.url}`)
    }),
  )
  return calls
}

function renderPage() {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/apprenants/import']}>
        <AppRoutes />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function choose(fileName = 'export.csv') {
  // Premier rendu (route chargée à la demande) parfois lent quand toute la suite tourne en parallèle.
  await screen.findByRole('option', { name: 'Promotion Fictive' }, { timeout: 5000 })
  fireEvent.change(screen.getByLabelText('Campus'), { target: { value: '1' } })
  fireEvent.change(screen.getByLabelText('Promotion'), { target: { value: '2' } })
  fireEvent.change(screen.getByLabelText('Fichier'), { target: { files: [new File(['x'], fileName, { type: 'text/csv' })] } })
}

describe('Import des apprenants', () => {
  it('aperçu par classe, confirmation sans classe choisie, rapport et fichier des mots de passe', async () => {
    const calls = mockApi((call) => {
      if (call.url === '/api/v1/learner-imports/preview') return json(200, { data: PREVIEW })
      if (call.method === 'POST' && call.url === '/api/v1/learner-imports') return json(201, { data: imported('termine') })
      return undefined
    })
    renderPage()
    await choose()
    fireEvent.click(screen.getByRole('button', { name: 'Voir l’aperçu' }))

    expect(await screen.findByText('Confirmer l’import (2 apprenants)')).toBeInTheDocument()
    expect(screen.getByText('CNIE manquante')).toBeInTheDocument()
    expect(screen.getByRole('cell', { name: 'AI LEEK' })).toBeInTheDocument()
    expect(screen.queryByLabelText('Classe')).not.toBeInTheDocument()
    const sent = calls.find((c) => c.url.endsWith('/preview'))?.body
    expect(sent).toMatchObject({ campus_id: '1', promotion_id: '2' })
    expect(sent).not.toHaveProperty('class_id')

    fireEvent.click(screen.getByText('Confirmer l’import (2 apprenants)'))
    expect(await screen.findByText(/Import terminé : 2 comptes créés/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Télécharger le fichier des mots de passe' })).toBeInTheDocument()
    expect(screen.getByText(/Téléchargeable jusqu’à sa suppression/)).toBeInTheDocument()
    const confirm = calls.find((c) => c.method === 'POST' && c.url === '/api/v1/learner-imports')
    expect(confirm?.body).toMatchObject({ campus_id: '1', fingerprint: PREVIEW.fingerprint })
    expect(String(confirm?.body?.confirmation_key)).toMatch(/^[0-9a-f-]{36}$/)
  })

  it('changer de promotion efface l’aperçu ; une réponse d’aperçu tardive est ignorée', async () => {
    let release: (r: Response) => void = () => undefined
    mockApi((call) => (call.url === '/api/v1/learner-imports/preview' ? new Promise<Response>((resolve) => (release = resolve)) : undefined))
    renderPage()
    await choose()
    fireEvent.click(screen.getByRole('button', { name: 'Voir l’aperçu' }))
    fireEvent.change(screen.getByLabelText('Promotion'), { target: { value: '' } })

    await act(async () => release(json(200, { data: PREVIEW })))
    expect(screen.queryByText(/Confirmer l’import/)).not.toBeInTheDocument()
  })

  it('erreur réseau à la confirmation : même clé renvoyée ; 409 : nouvel aperçu', async () => {
    let attempt = 0
    const calls = mockApi((call) => {
      if (call.url === '/api/v1/learner-imports/preview') return json(200, { data: PREVIEW })
      if (call.method === 'POST' && call.url === '/api/v1/learner-imports') {
        attempt += 1
        if (attempt === 1) throw new TypeError('réseau')
        if (attempt === 2) return json(201, { data: imported('termine') })
      }
      return undefined
    })
    renderPage()
    await choose()
    fireEvent.click(screen.getByRole('button', { name: 'Voir l’aperçu' }))
    fireEvent.click(await screen.findByText('Confirmer l’import (2 apprenants)'))
    expect(await screen.findByText(/Connexion au serveur impossible/)).toBeInTheDocument()
    fireEvent.click(screen.getByText('Confirmer l’import (2 apprenants)'))
    expect(await screen.findByText(/Import terminé/)).toBeInTheDocument()
    const keys = calls.filter((c) => c.method === 'POST' && c.url === '/api/v1/learner-imports').map((c) => c.body?.confirmation_key)
    expect(keys).toHaveLength(2)
    expect(keys[0]).toBe(keys[1])
  })

  it('409 « aperçu changé » : message et nouvel aperçu, nouvelle clé', async () => {
    let previews = 0
    const calls = mockApi((call) => {
      if (call.url === '/api/v1/learner-imports/preview') {
        previews += 1
        return json(200, { data: PREVIEW })
      }
      if (call.method === 'POST' && call.url === '/api/v1/learner-imports') return json(409, { message: 'L’aperçu a changé : vérifiez-le et confirmez à nouveau.', code: 'preview_changed' })
      return undefined
    })
    renderPage()
    await choose()
    fireEvent.click(screen.getByRole('button', { name: 'Voir l’aperçu' }))
    fireEvent.click(await screen.findByText('Confirmer l’import (2 apprenants)'))
    expect(await screen.findByText('L’aperçu a changé : vérifiez-le et confirmez à nouveau.')).toBeInTheDocument()
    await waitFor(() => expect(previews).toBe(2))
    expect(calls.filter((c) => c.method === 'POST' && c.url === '/api/v1/learner-imports')).toHaveLength(1)
  })

  it('import en file : « Import en cours… » puis échec et « Recommencer »', { timeout: 15000 }, async () => {
    mockApi((call) => {
      if (call.url === '/api/v1/learner-imports/preview') return json(200, { data: PREVIEW })
      if (call.method === 'POST' && call.url === '/api/v1/learner-imports') return json(201, { data: imported('en_attente') })
      if (call.url === '/api/v1/learner-imports/7') return json(200, { data: imported('echec', { error: 'Erreur technique : recommencez l’import.', has_passwords: false }) })
      return undefined
    })
    renderPage()
    await choose()
    fireEvent.click(screen.getByRole('button', { name: 'Voir l’aperçu' }))
    fireEvent.click(await screen.findByText('Confirmer l’import (2 apprenants)'))
    expect(await screen.findByText(/Import en cours…/)).toBeInTheDocument()

    // Le suivi interroge l'état toutes les 3 secondes (vraie horloge).
    expect(await screen.findByText('Erreur technique : recommencez l’import.', {}, { timeout: 6000 })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Recommencer' }))
    expect(screen.queryByText(/Erreur technique/)).not.toBeInTheDocument()
  })
  it('changer de promotion pendant l’aperçu libère le bouton ; un nouvel aperçu fonctionne (constat 3)', async () => {
    let first = true
    let release: (r: Response) => void = () => undefined
    mockApi((call) => {
      if (call.url !== '/api/v1/learner-imports/preview') return undefined
      if (first) {
        first = false
        return new Promise<Response>((resolve) => (release = resolve))
      }
      return json(200, { data: PREVIEW })
    })
    renderPage()
    await choose()
    fireEvent.click(screen.getByRole('button', { name: 'Voir l’aperçu' }))
    fireEvent.change(screen.getByLabelText('Promotion'), { target: { value: '' } })
    fireEvent.change(screen.getByLabelText('Promotion'), { target: { value: '2' } })

    expect(screen.getByRole('button', { name: 'Voir l’aperçu' })).toBeEnabled()
    fireEvent.click(screen.getByRole('button', { name: 'Voir l’aperçu' }))
    expect(await screen.findByText('Confirmer l’import (2 apprenants)')).toBeInTheDocument()
    await act(async () => release(json(500, { message: 'tardive' })))
    expect(screen.getByText('Confirmer l’import (2 apprenants)')).toBeInTheDocument()
  })

  it('erreur passagère du suivi : alerte puis effacée au retour du serveur (constat 8)', { timeout: 20000 }, async () => {
    let polls = 0
    mockApi((call) => {
      if (call.url === '/api/v1/learner-imports/preview') return json(200, { data: PREVIEW })
      if (call.method === 'POST' && call.url === '/api/v1/learner-imports') return json(201, { data: imported('en_attente') })
      if (call.url === '/api/v1/learner-imports/7') {
        polls += 1
        return polls === 1 ? json(500, { message: 'panne' }) : json(200, { data: imported('termine') })
      }
      return undefined
    })
    renderPage()
    await choose()
    fireEvent.click(screen.getByRole('button', { name: 'Voir l’aperçu' }))
    fireEvent.click(await screen.findByText('Confirmer l’import (2 apprenants)'))

    expect(await screen.findByText(/Le suivi de l’import est momentanément indisponible/, {}, { timeout: 6000 })).toBeInTheDocument()
    expect(await screen.findByText(/Import terminé : 2 comptes créés/, {}, { timeout: 6000 })).toBeInTheDocument()
    expect(screen.queryByText(/Le suivi de l’import est momentanément indisponible/)).not.toBeInTheDocument()
  })

  it('historique : rapport d’un ancien import (lignes rejetées) et pagination (constat 4)', async () => {
    const calls = mockApi((call) => {
      if (call.url === '/api/v1/learner-imports') return json(200, { data: [imported('termine')], meta: { current_page: 1, last_page: 2 } })
      if (call.url === '/api/v1/learner-imports?page=2') return json(200, { data: [imported('echec', { id: 6, filename: 'ancien.csv', has_passwords: false })], meta: { current_page: 2, last_page: 2 } })
      if (call.url === '/api/v1/learner-imports/7') return json(200, { data: imported('termine') })
      return undefined
    })
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Voir le rapport de l’import 7' }))

    expect(await screen.findByText(/Import terminé : 2 comptes créés/)).toBeInTheDocument()
    expect(screen.getByText('Lignes rejetées (1)')).toBeInTheDocument()
    expect(screen.getByText('CNIE manquante')).toBeInTheDocument()

    expect(screen.getByText('Page 1 sur 2')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Suivant' }))
    expect(await screen.findByText('ancien.csv')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Suivant' })).toBeDisabled()
    expect(calls.some((c) => c.url === '/api/v1/learner-imports?page=2')).toBe(true)
  })

  /** Deux imports dans l'historique ; la première lecture de chacun attend `release`. */
  function twoReports() {
    const pending: Record<number, (r: Response) => void> = {}
    const seen: Record<number, number> = {}
    const report = (id: number, extra: Partial<LearnerImport> = {}) =>
      imported('termine', { id, filename: `import-${id}.csv`, counts: { ...PREVIEW.counts, create: id }, ...extra })
    const calls = mockApi((call) => {
      if (call.method === 'GET' && call.url === '/api/v1/learner-imports') return json(200, { data: [report(11), report(12)], meta: { current_page: 1, last_page: 1 } })
      if (call.method === 'DELETE') return new Response(null, { status: 204 })
      const match = /\/learner-imports\/(\d+)$/.exec(call.url)
      if (!match) return undefined
      const id = Number(match[1])
      seen[id] = (seen[id] ?? 0) + 1
      if (seen[id] === 1) return new Promise<Response>((resolve) => (pending[id] = resolve))
      return json(200, { data: report(id, { has_passwords: false }) })
    })
    return { calls, pending, report }
  }

  it('rapports A puis B, réponses B puis A : B reste affiché et la suppression vise B (N1)', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const { calls, pending, report } = twoReports()
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Voir le rapport de l’import 11' }, { timeout: 5000 }))
    fireEvent.click(screen.getByRole('button', { name: 'Voir le rapport de l’import 12' }))
    await waitFor(() => expect(pending[11] && pending[12]).toBeTruthy())

    await act(async () => pending[12](json(200, { data: report(12) })))
    expect(await screen.findByText(/Import terminé : 12 comptes créés/)).toBeInTheDocument()
    await act(async () => pending[11](json(200, { data: report(11) })))
    expect(screen.getByText(/Import terminé : 12 comptes créés/)).toBeInTheDocument()
    expect(screen.queryByText(/Import terminé : 11 comptes créés/)).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Supprimer le fichier des mots de passe' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'DELETE')).toBe(true))
    expect(calls.filter((c) => c.method === 'DELETE').map((c) => c.url)).toEqual(['/api/v1/learner-imports/12/passwords'])
    confirm.mockRestore()
  })

  it('réponse d’un ancien rapport après « Nouvel import » : ignorée (N1)', async () => {
    const { pending, report } = twoReports()
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Voir le rapport de l’import 11' }, { timeout: 5000 }))
    fireEvent.click(screen.getByRole('button', { name: 'Voir le rapport de l’import 12' }))
    await waitFor(() => expect(pending[11] && pending[12]).toBeTruthy())
    await act(async () => pending[12](json(200, { data: report(12) })))
    fireEvent.click(await screen.findByRole('button', { name: 'Nouvel import' }))

    await act(async () => pending[11](json(200, { data: report(11) })))
    expect(screen.queryByText(/Import terminé/)).not.toBeInTheDocument()
  })

  it('réponse tardive du suivi après l’ouverture d’un autre rapport : ignorée (N1)', { timeout: 15000 }, async () => {
    let release: (r: Response) => void = () => undefined
    let polls = 0
    const done = (id: number) => imported('termine', { id, counts: { ...PREVIEW.counts, create: id } })
    mockApi((call) => {
      if (call.url === '/api/v1/learner-imports') return json(200, { data: [imported('en_cours', { id: 7 }), done(12)], meta: { current_page: 1, last_page: 1 } })
      if (call.url === '/api/v1/learner-imports/12') return json(200, { data: done(12) })
      if (call.url === '/api/v1/learner-imports/7') {
        polls += 1
        return polls === 1 ? json(200, { data: imported('en_cours', { id: 7 }) }) : new Promise<Response>((resolve) => (release = resolve))
      }
      return undefined
    })
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Voir le rapport de l’import 7' }, { timeout: 5000 }))
    expect(await screen.findByText(/Import en cours…/)).toBeInTheDocument()
    await waitFor(() => expect(polls).toBe(2), { timeout: 6000 })

    fireEvent.click(screen.getByRole('button', { name: 'Voir le rapport de l’import 12' }))
    expect(await screen.findByText(/Import terminé : 12 comptes créés/)).toBeInTheDocument()
    await act(async () => release(json(200, { data: done(7) })))
    expect(screen.getByText(/Import terminé : 12 comptes créés/)).toBeInTheDocument()
    expect(screen.queryByText(/Import terminé : 7 comptes créés/)).not.toBeInTheDocument()
  })

  it('suppression en erreur puis réussie : l’ancienne erreur disparaît (N5)', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    let attempts = 0
    mockApi((call) => {
      if (call.url === '/api/v1/learner-imports') return json(200, { data: [imported('termine')], meta: { current_page: 1, last_page: 1 } })
      if (call.method === 'DELETE') {
        attempts += 1
        return attempts === 1 ? json(500, { message: 'Suppression impossible.' }) : new Response(null, { status: 204 })
      }
      return undefined
    })
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Supprimer' }, { timeout: 5000 }))
    expect(await screen.findByRole('alert')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Supprimer' }))
    expect(await screen.findByText('Fichier des mots de passe supprimé.')).toBeInTheDocument()
    expect(attempts).toBe(2)
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    confirm.mockRestore()
  })
})
