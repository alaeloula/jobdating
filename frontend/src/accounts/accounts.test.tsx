import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import AppRoutes from '../AppRoutes'
import type { CurrentUser, RoleName } from '../auth/types'
import type { StaffAccount } from './api'

function user(role: RoleName, permissions: string[]): CurrentUser {
  return {
    id: 1, first_name: 'Ada', last_name: 'Admin', email: 'ada@example.test', username: null,
    role, role_label: role, permissions, home: '/pilotage',
  }
}

const ADMIN = user('administrateur', ['users.manage', 'audit.view'])

function account(id: number, last: string, status: StaffAccount['status'], extra: Partial<StaffAccount> = {}): StaffAccount {
  return {
    id, first_name: 'Prénom', last_name: last, full_name: `Prénom ${last}`, email: `${last.toLowerCase()}@example.test`,
    phone: null, role: 'cme', role_label: 'CME – Chargé Média Emploi', status, last_login_at: null, is_self: false, ...extra,
  }
}

function json(status: number, body?: unknown): Response {
  return new Response(body === undefined ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

interface Call {
  method: string
  url: string
  body: Record<string, unknown> | undefined
}

function mockApi(me: CurrentUser, rows: StaffAccount[], handler: (call: Call) => Response | undefined = () => undefined) {
  const calls: Call[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const call: Call = {
        method: init?.method ?? 'GET',
        url: String(input),
        body: init?.body ? (JSON.parse(String(init.body)) as Record<string, unknown>) : undefined,
      }
      calls.push(call)
      if (call.url === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
      if (call.url === '/api/v1/me') return json(200, { data: me })
      const custom = handler(call)
      if (custom) return custom
      if (call.method === 'GET' && call.url.startsWith('/api/v1/users')) {
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

describe('Comptes CME et Direction', () => {
  it("l'administrateur voit le lien, la liste et les statuts ; pas d'action destructrice sur son propre compte", async () => {
    mockApi(ADMIN, [
      account(1, 'Admin', 'actif', { is_self: true, role: 'administrateur', role_label: 'Administrateur' }),
      account(2, 'Invitee', 'invitation'),
      account(3, 'Desactive', 'desactive'),
    ])
    renderAt('/comptes')

    expect(await screen.findByRole('heading', { name: 'Comptes CME et Direction' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Comptes CME et Direction' })).toBeInTheDocument()
    expect(await screen.findByText('Invitation envoyée')).toBeInTheDocument()

    const self = screen.getByText('Prénom Admin').closest('tr') as HTMLElement
    expect(within(self).queryByRole('button', { name: 'Désactiver' })).not.toBeInTheDocument()
    expect(within(self).queryByRole('button', { name: 'Supprimer' })).not.toBeInTheDocument()

    const invited = screen.getByText('Prénom Invitee').closest('tr') as HTMLElement
    expect(within(invited).getByRole('button', { name: 'Renvoyer le lien' })).toBeInTheDocument()
    const off = screen.getByText('Prénom Desactive').closest('tr') as HTMLElement
    expect(within(off).getByRole('button', { name: 'Réactiver' })).toBeInTheDocument()
  })

  it('crée un membre : envoi du rôle choisi et message', async () => {
    const calls = mockApi(ADMIN, [], (call) => (call.method === 'POST' && call.url === '/api/v1/users' ? json(201, { data: {} }) : undefined))
    renderAt('/comptes')

    fireEvent.change(await screen.findByLabelText('Prénom'), { target: { value: 'Nora' } })
    fireEvent.change(screen.getByLabelText('Nom'), { target: { value: 'Nouvelle' } })
    fireEvent.change(screen.getByLabelText('E-mail'), { target: { value: 'nora@example.test' } })
    fireEvent.click(screen.getByRole('radio', { name: /Direction/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Créer et envoyer le lien d’activation' }))

    expect(await screen.findByText('Compte créé. Le lien d’activation est envoyé à nora@example.test.')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'POST' && c.url === '/api/v1/users')?.body).toEqual({
      first_name: 'Nora', last_name: 'Nouvelle', email: 'nora@example.test', phone: null, role: 'direction',
    })
    await waitFor(() => expect(screen.getByLabelText('Prénom')).toHaveValue(''))
  })

  it('affiche les erreurs de validation du serveur sous les champs', async () => {
    mockApi(ADMIN, [], (call) =>
      call.method === 'POST' ? json(422, { message: 'x', errors: { email: ['Cette adresse e-mail est déjà utilisée.'] } }) : undefined,
    )
    renderAt('/comptes')

    fireEvent.change(await screen.findByLabelText('E-mail'), { target: { value: 'pris@example.test' } })
    fireEvent.click(screen.getByRole('button', { name: 'Créer et envoyer le lien d’activation' }))

    expect(await screen.findByText('Cette adresse e-mail est déjà utilisée.')).toBeInTheDocument()
  })

  it('réinitialise après confirmation, affiche le mot de passe une fois, sans le garder dans le cache', async () => {
    const calls = mockApi(ADMIN, [account(5, 'Camille', 'actif')], (call) =>
      call.url === '/api/v1/users/5/reset-password' ? json(200, { data: { password: 'Abcdefgh23456789' } }) : undefined,
    )
    const queryClient = renderAt('/comptes')

    fireEvent.click(await screen.findByRole('button', { name: 'Réinitialiser le mot de passe' }))
    expect(calls.some((c) => c.url.includes('reset-password'))).toBe(false)
    fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Confirmer' }))

    expect(await screen.findByTestId('generated-password')).toHaveTextContent('Abcdefgh23456789')
    const cache = JSON.stringify([
      queryClient.getQueryCache().getAll().map((q) => q.state.data ?? null),
      queryClient.getMutationCache().getAll().map((m) => m.state.data ?? null),
    ])
    expect(cache).not.toContain('Abcdefgh23456789')

    fireEvent.click(screen.getByRole('button', { name: 'Fermer' }))
    expect(screen.queryByTestId('generated-password')).not.toBeInTheDocument()
  })

  it('supprime après confirmation et affiche le refus du serveur', async () => {
    const calls = mockApi(ADMIN, [account(6, 'Dernier', 'actif', { role: 'administrateur', role_label: 'Administrateur' })], (call) =>
      call.method === 'DELETE' ? json(422, { message: 'Il doit toujours rester au moins un administrateur actif.', code: 'last_admin' }) : undefined,
    )
    renderAt('/comptes')

    fireEvent.click(await screen.findByRole('button', { name: 'Supprimer' }))
    fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Confirmer' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Il doit toujours rester au moins un administrateur actif.')
    expect(calls.find((c) => c.method === 'DELETE')?.url).toBe('/api/v1/users/6')
  })

  it('filtre « Supprimés » : bouton Restaurer', async () => {
    const calls = mockApi(ADMIN, [account(7, 'Ancien', 'supprime')], (call) =>
      call.url === '/api/v1/users/7/restore' ? json(200, { data: {} }) : undefined,
    )
    renderAt('/comptes')

    fireEvent.change(await screen.findByLabelText('Filtrer par statut'), { target: { value: 'supprime' } })
    await waitFor(() => expect(calls.some((c) => c.url === '/api/v1/users?status=supprime')).toBe(true))
    fireEvent.click(await screen.findByRole('button', { name: 'Restaurer' }))

    expect(await screen.findByText('Compte de Prénom Ancien restauré.')).toBeInTheDocument()
  })

  // Revue Codex T-10, constat 3 : un échec de chargement n'est pas présenté comme une liste vide.
  it('échec du chargement : message et nouvel essai', async () => {
    let fail = true
    mockApi(ADMIN, [account(8, 'Retrouve', 'actif')], (call) =>
      call.method === 'GET' && call.url.startsWith('/api/v1/users') && fail ? json(503, { message: 'Service indisponible.' }) : undefined,
    )
    renderAt('/comptes')

    expect(await screen.findByText('La liste des comptes n’a pas pu être chargée.')).toBeInTheDocument()
    expect(screen.queryByText('Aucun compte.')).not.toBeInTheDocument()

    fail = false
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }))
    expect(await screen.findByText('Prénom Retrouve')).toBeInTheDocument()
  })

  it("la CME n'a ni le lien ni l'écran", async () => {
    const calls = mockApi(user('cme', ['referentials.manage']), [])
    renderAt('/comptes')

    expect(await screen.findByRole('heading', { name: 'Tableau de bord de pilotage' })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Comptes CME et Direction' })).not.toBeInTheDocument()
    expect(calls.some((c) => c.url.startsWith('/api/v1/users'))).toBe(false)
  })
})
