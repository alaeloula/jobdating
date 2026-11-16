import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import AppRoutes from '../AppRoutes'
import type { CurrentUser, RoleName } from '../auth/types'
import type { AuditLogEntry } from './api'

function user(role: RoleName, permissions: string[]): CurrentUser {
  return {
    id: 1, first_name: 'Prénom', last_name: 'Fictif', email: 'compte@example.test', username: null,
    role, role_label: role, permissions, home: role === 'apprenant' ? '/ma-journee' : '/pilotage',
  }
}

const ENTRY: AuditLogEntry = {
  id: 7,
  created_at: '2026-11-16T10:42:00+01:00',
  actor: { id: 1, label: 'Admin Fictif (admin@example.test)' },
  action: 'account.role_changed',
  action_label: 'Rôle modifié',
  category: 'comptes',
  category_label: 'Comptes et mots de passe',
  subject: { type: 'user', id: 9, label: 'Cible Fictive (cible@example.test)' },
  old_values: { role: 'cme' },
  new_values: { role: 'direction' },
  ip: '10.0.0.1',
}

function json(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

/** fetch simulé : routage par début d'URL ; renvoie la liste des URL appelées. */
function mockApi(me: CurrentUser) {
  const calls: string[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input)
      calls.push(url)
      if (url === '/api/v1/me') return json(200, { data: me })
      if (url === '/api/v1/audit-logs/filters') {
        return json(200, {
          data: {
            authors: [{ id: 1, label: 'Admin Fictif (admin@example.test)' }],
            categories: [
              { value: 'connexions', label: 'Connexions' },
              { value: 'comptes', label: 'Comptes et mots de passe' },
            ],
          },
        })
      }
      if (url.startsWith('/api/v1/audit-logs?')) {
        return json(200, { data: [ENTRY], meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 } })
      }
      throw new Error(`Appel inattendu : ${url}`)
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

describe('Journal d’audit', () => {
  it("l'administrateur voit le journal et le lien de navigation", async () => {
    mockApi(user('administrateur', ['audit.view']))
    renderAt('/journal-audit')

    expect(await screen.findByRole('heading', { name: 'Journal d’audit' })).toBeInTheDocument()
    expect(await screen.findByText('Rôle modifié')).toBeInTheDocument()
    expect(screen.getByText('Admin Fictif (admin@example.test)', { selector: 'td' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Journal d’audit' })).toBeInTheDocument()
  })

  it('un filtre de catégorie relance la recherche avec ce filtre', async () => {
    const calls = mockApi(user('administrateur', ['audit.view']))
    renderAt('/journal-audit')

    fireEvent.click(await screen.findByRole('button', { name: 'Connexions' }))

    await waitFor(() => expect(calls.some((u) => u.includes('category=connexions'))).toBe(true))
    expect(screen.getByRole('button', { name: 'Connexions' })).toHaveAttribute('aria-pressed', 'true')
  })

  it('le détail affiche les valeurs avant et après', async () => {
    mockApi(user('administrateur', ['audit.view']))
    renderAt('/journal-audit')

    fireEvent.click(await screen.findByRole('button', { name: /Cible Fictive/ }))

    expect(await screen.findByRole('columnheader', { name: 'Avant' })).toBeInTheDocument()
    expect(screen.getByRole('cell', { name: 'cme' })).toBeInTheDocument()
    expect(screen.getByRole('cell', { name: 'direction' })).toBeInTheDocument()
    expect(screen.getByText(/Adresse IP : 10\.0\.0\.1/)).toBeInTheDocument()
  })

  it("un autre rôle n'a ni le lien ni l'accès à l'écran", async () => {
    const calls = mockApi(user('cme', []))
    renderAt('/journal-audit')

    expect(await screen.findByRole('heading', { name: 'Tableau de bord de pilotage' })).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent("Vous n'avez pas accès à cette page.")
    expect(screen.queryByRole('link', { name: 'Journal d’audit' })).not.toBeInTheDocument()
    expect(calls.some((u) => u.startsWith('/api/v1/audit-logs'))).toBe(false)
  })
})
