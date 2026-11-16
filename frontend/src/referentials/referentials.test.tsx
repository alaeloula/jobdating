import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import AppRoutes from '../AppRoutes'
import type { CurrentUser, RoleName } from '../auth/types'

function user(role: RoleName, permissions: string[]): CurrentUser {
  return {
    id: 1, first_name: 'Prénom', last_name: 'Fictif', email: 'compte@example.test', username: null,
    role, role_label: role, permissions, home: '/pilotage',
  }
}

function json(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

interface Call {
  method: string
  url: string
  body: unknown
}

type Handler = (call: Call) => Response | undefined

/** fetch simulé : chaque appel est enregistré et passé aux gestionnaires dans l'ordre. */
function mockApi(me: CurrentUser, handler: Handler = () => undefined) {
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
      if (call.url.startsWith('/api/v1/referentials/filieres/options')) {
        return json(200, { data: [{ id: 1, label: 'JAVA – Java', active: true, code: 'JAVA' }, { id: 2, label: 'JS – JavaScript', active: true, code: 'JS' }] })
      }
      if (call.method === 'GET' && call.url === '/api/v1/referentials/filieres') {
        return json(200, { data: [{ id: 1, display_label: 'JAVA – Java', active: true, code: 'JAVA', label: 'Java' }] })
      }
      if (call.method === 'GET' && call.url === '/api/v1/referentials/classes') {
        return json(200, { data: [{ id: 5, display_label: 'Classe A', active: true, name: 'Classe A', filiere_id: 1 }] })
      }
      if (call.method === 'GET' && call.url.startsWith('/api/v1/referentials/')) return json(200, { data: [] })
      if (call.method === 'POST' || call.method === 'PATCH') return json(200, { data: {} })
      throw new Error(`Appel inattendu : ${call.method} ${call.url}`)
    }),
  )
  return calls
}

function renderAt(path: string) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <AppRoutes />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const CME = user('cme', ['referentials.manage'])

describe('Référentiels', () => {
  it("la CME voit les 8 onglets, le lien et les filières", async () => {
    mockApi(CME)
    renderAt('/referentiels')

    expect(await screen.findByRole('heading', { name: 'Référentiels' })).toBeInTheDocument()
    expect(screen.getAllByRole('tab')).toHaveLength(8)
    expect(screen.getByRole('tab', { name: 'Compétences' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Référentiels' })).toBeInTheDocument()
    expect(await screen.findByRole('cell', { name: 'JAVA' })).toBeInTheDocument()
  })

  it('ajoute une filière', async () => {
    const calls = mockApi(CME)
    renderAt('/referentiels')

    fireEvent.change(await screen.findByLabelText('Code'), { target: { value: 'PHP' } })
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'PHP / Laravel' } })
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ code: 'PHP', label: 'PHP / Laravel' }))
    // Formulaire vidé après l'ajout.
    await waitFor(() => expect(screen.getByLabelText('Code')).toHaveValue(''))
    expect(screen.getByLabelText('Libellé')).toHaveValue('')
  })

  it('désactive une valeur', async () => {
    const calls = mockApi(CME)
    renderAt('/referentiels')

    fireEvent.click(await screen.findByRole('button', { name: 'Désactiver' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'PATCH')).toMatchObject({ url: '/api/v1/referentials/filieres/1', body: { active: false } }))
  })

  it('le code d’une filière est en lecture seule en modification', async () => {
    mockApi(CME)
    renderAt('/referentiels')

    fireEvent.click(await screen.findByRole('button', { name: 'Modifier' }))

    expect(screen.getByLabelText('Code')).toHaveAttribute('readonly')
    expect(screen.getByText('Le code d’une filière est définitif.')).toBeInTheDocument()
  })

  it('demande une confirmation quand la classe compte des apprenants', async () => {
    let patches = 0
    const calls = mockApi(CME, (call) => {
      if (call.method === 'PATCH') {
        patches += 1
        return patches === 1
          ? json(409, { message: 'Cette classe compte 3 apprenant(s).', code: 'class_in_use', learners_count: 3 })
          : json(200, { data: {} })
      }
      return undefined
    })
    renderAt('/referentiels')

    fireEvent.click(await screen.findByRole('tab', { name: 'Classes' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Modifier' }))
    const select = await screen.findByLabelText('Filière')
    await waitFor(() => expect(within(select).getByRole('option', { name: 'JS – JavaScript' })).toBeInTheDocument())
    fireEvent.change(select, { target: { value: '2' } })
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))

    const dialog = await screen.findByRole('alertdialog')
    expect(dialog).toHaveTextContent('3 apprenant(s) changeront de filière.')

    fireEvent.click(within(dialog).getByRole('button', { name: 'Confirmer le changement' }))

    await waitFor(() => expect(patches).toBe(2))
    const last = calls.filter((c) => c.method === 'PATCH').at(-1)
    expect(last?.body).toEqual({ name: 'Classe A', filiere_id: 2, confirmer_changement_filiere: true })
    expect(calls.filter((c) => c.method === 'PATCH')[0]?.body).not.toHaveProperty('confirmer_changement_filiere')
  })

  it('déplace une opportunité et envoie le nouvel ordre complet', async () => {
    const calls = mockApi(CME, (call) => {
      if (call.method === 'GET' && call.url === '/api/v1/referentials/opportunites') {
        return json(200, { data: [
          { id: 7, display_label: 'Stage', active: true, label: 'Stage', position: 1 },
          { id: 8, display_label: 'Alternance', active: true, label: 'Alternance', position: 2 },
          { id: 9, display_label: 'CDI', active: true, label: 'CDI', position: 3 },
        ] })
      }
      if (call.method === 'PUT') return new Response(null, { status: 204 })
      return undefined
    })
    renderAt('/referentiels')

    fireEvent.click(await screen.findByRole('tab', { name: 'Opportunités' }))
    expect(await screen.findByRole('button', { name: 'Monter Stage' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Descendre CDI' })).toBeDisabled()

    fireEvent.click(screen.getByRole('button', { name: 'Descendre Stage' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'PUT')).toMatchObject({
      url: '/api/v1/referentials/opportunites/order',
      body: { ids: [8, 7, 9] },
    }))
  })

  it("la Direction n'a ni le lien ni l'écran", async () => {
    const calls = mockApi(user('direction', []))
    renderAt('/referentiels')

    expect(await screen.findByRole('heading', { name: 'Tableau de bord de pilotage' })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Référentiels' })).not.toBeInTheDocument()
    expect(calls.some((c) => c.url.startsWith('/api/v1/referentials'))).toBe(false)
  })
})
