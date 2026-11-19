import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import AppRoutes from '../AppRoutes'
import type { CurrentUser, RoleName } from '../auth/types'
import type { Company } from './api'

const PASSWORD = 'Mot2PasseGenere9'

function user(role: RoleName, permissions: string[], extra: Partial<CurrentUser> = {}): CurrentUser {
  return {
    id: 1, first_name: 'Prénom', last_name: 'Fictif', email: 'compte@example.test', username: null,
    role, role_label: role, permissions, home: role === 'entreprise' ? '/entreprise' : '/pilotage', ...extra,
  }
}

const ADMIN = user('administrateur', ['companies.manage', 'companies.profiles.manage'])
const CME = user('cme', ['companies.profiles.manage', 'referentials.manage'])

function company(id: number, name: string, extra: Partial<Company> = {}): Company {
  return {
    id, name, sector: null, description: null, website: null, contact_name: null, contact_email: null, has_logo: false,
    username: name.toLowerCase(), status: 'actif', credentials_version: 1, credentials_printed: false,
    filieres: [], competences: [], recruiters: [], ...extra,
  }
}

function json(status: number, body?: unknown): Response {
  return new Response(body === undefined ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

interface Call {
  method: string
  url: string
  body: unknown
}

function mockApi(me: CurrentUser, rows: Company[], handler: (call: Call) => Response | undefined = () => undefined) {
  const calls: Call[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const raw = init?.body
      const call: Call = {
        method: init?.method ?? 'GET',
        url: String(input),
        body: raw instanceof FormData ? Object.fromEntries(raw.entries()) : raw ? JSON.parse(String(raw)) : undefined,
      }
      calls.push(call)
      if (call.url === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
      if (call.url === '/api/v1/me') return json(200, { data: me })
      const custom = handler(call)
      if (custom) return custom
      if (call.url.startsWith('/api/v1/referentials/secteurs/options')) return json(200, { data: [{ id: 3, label: 'Banque', active: true }] })
      if (call.url.startsWith('/api/v1/referentials/filieres/options')) return json(200, { data: [{ id: 1, label: 'JAVA – Java', active: true }] })
      if (call.url.startsWith('/api/v1/referentials/competences/options')) return json(200, { data: [{ id: 7, label: '.NET', active: true }] })
      if (call.method === 'GET' && call.url.startsWith('/api/v1/companies')) {
        return json(200, { data: rows, meta: { current_page: 1, last_page: 1, total: rows.length } })
      }
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

describe('Entreprises', () => {
  it('crée une entreprise : nom d’utilisateur proposé, fiche affichée une fois, impression confirmée, rien dans le cache', async () => {
    const created = company(9, 'Acme Maroc', { username: 'acme-maroc' })
    const calls = mockApi(ADMIN, [], (call) => {
      if (call.url.startsWith('/api/v1/companies/username-suggestion')) return json(200, { data: { username: 'acme-maroc' } })
      if (call.method === 'POST' && call.url === '/api/v1/companies') {
        return json(201, { data: created, credentials: { username: 'acme-maroc', password: PASSWORD, version: 1, url: 'http://localhost:5173' } })
      }
      if (call.url === '/api/v1/companies/9/credentials-printed') return json(200, { data: { ...created, credentials_printed: true } })
      return undefined
    })
    const queryClient = renderAt('/entreprises')

    fireEvent.click(await screen.findByRole('button', { name: 'Nouvelle entreprise' }))
    const name = screen.getByLabelText('Nom de l’entreprise')
    fireEvent.change(name, { target: { value: 'Acme Maroc' } })
    fireEvent.blur(name)
    await waitFor(() => expect(screen.getByLabelText('Nom d’utilisateur')).toHaveValue('acme-maroc'))
    fireEvent.change(screen.getByLabelText('Secteur'), { target: { value: '3' } })
    fireEvent.click(screen.getByRole('button', { name: 'Créer l’entreprise' }))

    expect(await screen.findByTestId('sheet-password')).toHaveTextContent(PASSWORD)
    expect(calls.find((c) => c.method === 'POST' && c.url === '/api/v1/companies')?.body).toMatchObject({
      name: 'Acme Maroc', username: 'acme-maroc', sector_id: '3',
    })

    fireEvent.click(screen.getByRole('button', { name: 'J’ai imprimé la fiche' }))
    expect(await screen.findByText('Impression confirmée.')).toBeInTheDocument()
    expect(calls.find((c) => c.url === '/api/v1/companies/9/credentials-printed')?.body).toEqual({ version: 1 })
    expect(cacheHas(queryClient, PASSWORD)).toBe(false)

    fireEvent.click(screen.getByRole('button', { name: 'Fermer' }))
    expect(screen.queryByText(PASSWORD)).not.toBeInTheDocument()
  })

  it('confirmation d’une fiche périmée : message du serveur', async () => {
    mockApi(ADMIN, [company(4, 'Beta')], (call) => {
      if (call.url === '/api/v1/companies/4/reset-password') {
        return json(200, { data: company(4, 'Beta', { credentials_version: 3 }), credentials: { username: 'beta', password: PASSWORD, version: 3, url: 'x' } })
      }
      if (call.url === '/api/v1/companies/4/credentials-printed') {
        return json(409, { message: 'Cette fiche n’est plus valable : un nouveau mot de passe a été généré. Imprimez la nouvelle fiche.', code: 'stale_credentials' })
      }
      return undefined
    })
    renderAt('/entreprises')

    fireEvent.click(await screen.findByRole('button', { name: 'Réinitialiser le mot de passe' }))
    fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Confirmer' }))
    expect(await screen.findByTestId('sheet-password')).toHaveTextContent(PASSWORD)

    fireEvent.click(screen.getByRole('button', { name: 'J’ai imprimé la fiche' }))
    expect(await screen.findByText(/Cette fiche n’est plus valable/)).toBeInTheDocument()
  })

  // Revue Codex T-11, constat 2 : une nouvelle fiche ne reprend pas l'état de la précédente.
  it('deux réinitialisations sans fermer la fiche : la nouvelle fiche est à confirmer', async () => {
    let version = 1
    mockApi(ADMIN, [company(4, 'Beta')], (call) => {
      if (call.url === '/api/v1/companies/4/reset-password') {
        version += 1
        return json(200, {
          data: company(4, 'Beta', { credentials_version: version }),
          credentials: { username: 'beta', password: `Secret${version}AbcdefGH`, version, url: 'x' },
        })
      }
      if (call.url === '/api/v1/companies/4/credentials-printed') return json(200, { data: company(4, 'Beta', { credentials_printed: true }) })
      return undefined
    })
    renderAt('/entreprises')

    const reset = async () => {
      fireEvent.click(await screen.findByRole('button', { name: 'Réinitialiser le mot de passe' }))
      fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Confirmer' }))
    }
    await reset()
    expect(await screen.findByTestId('sheet-password')).toHaveTextContent('Secret2AbcdefGH')
    fireEvent.click(screen.getByRole('button', { name: 'J’ai imprimé la fiche' }))
    expect(await screen.findByText('Impression confirmée.')).toBeInTheDocument()

    await reset()
    // L'ancienne fiche est encore affichée pendant la requête : attendre la nouvelle version.
    await waitFor(() => expect(screen.getByTestId('sheet-password')).toHaveTextContent('Secret3AbcdefGH'))
    expect(screen.queryByText('Impression confirmée.')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'J’ai imprimé la fiche' })).toBeEnabled()
  })

  it('les profils recherchés sont visibles dans la liste ; logo en cercle ou initiales', async () => {
    mockApi(ADMIN, [
      company(6, 'Delta Conseil', { has_logo: true, filieres: [{ id: 1, label: 'JAVA – Java', active: true }], competences: [{ id: 7, label: '.NET', active: true }] }),
      company(7, 'Epsilon'),
    ])
    renderAt('/entreprises')

    const profiles = await screen.findByRole('list', { name: 'Profils recherchés par Delta Conseil' })
    expect(within(profiles).getByText('JAVA – Java')).toBeInTheDocument()
    expect(within(profiles).getByText('.NET')).toBeInTheDocument()
    expect(screen.getByRole('img', { name: 'Logo de Delta Conseil' })).toHaveClass('rounded-full')
    expect(screen.getByText('Aucun profil recherché')).toBeInTheDocument()
    expect(screen.getByText('E')).toBeInTheDocument()
  })

  it('logo qui ne se charge pas : initiales à la place', async () => {
    mockApi(ADMIN, [company(6, 'Delta Conseil', { has_logo: true })])
    renderAt('/entreprises')

    fireEvent.error(await screen.findByRole('img', { name: 'Logo de Delta Conseil' }))
    expect(screen.queryByRole('img', { name: 'Logo de Delta Conseil' })).not.toBeInTheDocument()
    expect(screen.getByText('DC')).toBeInTheDocument()
  })

  it('logo refusé : le message disparaît dès qu’un autre fichier est choisi', async () => {
    const row = company(9, 'Acme Maroc')
    mockApi(ADMIN, [row], (call) => {
      if (call.method === 'PATCH' && call.url === '/api/v1/companies/9') return json(200, { data: row })
      if (call.method === 'POST' && call.url === '/api/v1/companies/9/logo') {
        return json(422, { message: 'Logo invalide.', errors: { logo: ['Le logo ne doit pas dépasser 2 000 × 2 000 pixels.'] } })
      }
      return undefined
    })
    renderAt('/entreprises')

    fireEvent.click(await screen.findByRole('button', { name: 'Modifier' }))
    const input = screen.getByLabelText('Logo (PNG, JPG ou WebP, 1 Mo au plus)')
    fireEvent.change(input, { target: { files: [new File(['x'], 'trop-grand.png', { type: 'image/png' })] } })
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))
    expect(await screen.findByText('Le logo ne doit pas dépasser 2 000 × 2 000 pixels.')).toBeInTheDocument()

    fireEvent.change(input, { target: { files: [new File(['y'], 'correct.png', { type: 'image/png' })] } })
    expect(screen.queryByText('Le logo ne doit pas dépasser 2 000 × 2 000 pixels.')).not.toBeInTheDocument()
  })

  it('la CME consulte et saisit les profils recherchés, avec le bandeau, sans gérer les comptes', async () => {
    const calls = mockApi(
      CME,
      [company(5, 'Gamma', { competences: [{ id: 99, label: 'Cobol', active: false }], recruiters: [{ id: 1, name: 'Rania Recruteuse', email: 'r@example.test', phone: '0600', job_title: null }] })],
      (call) => (call.method === 'PUT' ? json(200, { data: company(5, 'Gamma') }) : undefined),
    )
    renderAt('/entreprises')

    expect(await screen.findByText('Gamma')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Nouvelle entreprise' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Réinitialiser le mot de passe' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Modifier' })).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Profils recherchés' }))
    expect(await screen.findByText('À renseigner uniquement avec l’accord de l’entreprise.')).toBeInTheDocument()
    expect(screen.getByText('Rania Recruteuse')).toBeInTheDocument()
    expect(screen.getByText('(inactive)')).toBeInTheDocument()

    fireEvent.click(await screen.findByRole('checkbox', { name: /JAVA – Java/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer les profils' }))

    expect(await screen.findByText('Profils recherchés enregistrés.')).toBeInTheDocument()
    // La compétence devenue inactive reste envoyée tant qu'elle n'est pas décochée.
    expect(calls.find((c) => c.method === 'PUT')?.body).toEqual({ filiere_ids: [1], competence_ids: [99] })
  })

  it("l'entreprise modifie sa présentation depuis « Mon profil »", async () => {
    const calls = mockApi(user('entreprise', [], { username: 'acme', email: null }), [], (call) => {
      if (call.url === '/api/v1/me/company' && call.method === 'GET') return json(200, { data: { name: 'Acme', description: null, website: null } })
      if (call.url === '/api/v1/me/company' && call.method === 'PUT') return json(200, { data: { name: 'Acme', description: 'Nous recrutons.', website: null } })
      return undefined
    })
    renderAt('/profil')

    fireEvent.change(await screen.findByLabelText('Description'), { target: { value: 'Nous recrutons.' } })
    fireEvent.click(within(screen.getByRole('region', { name: 'Présentation' })).getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByText('Présentation enregistrée.')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'PUT' && c.url === '/api/v1/me/company')?.body).toEqual({ description: 'Nous recrutons.', website: null })
  })

  it("la Direction n'a ni le lien ni l'écran", async () => {
    const calls = mockApi(user('direction', ['companies.view']), [])
    renderAt('/entreprises')

    expect(await screen.findByRole('heading', { name: 'Tableau de bord de pilotage' })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Entreprises participantes' })).not.toBeInTheDocument()
    expect(calls.some((c) => c.url.startsWith('/api/v1/companies'))).toBe(false)
  })
})
