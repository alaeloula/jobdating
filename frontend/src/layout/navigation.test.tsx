import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import AppRoutes from '../AppRoutes'
import { LOGOUT_FAILED_MESSAGE } from './AppLayout'
import type { CurrentUser, RoleName } from '../auth/types'

const HOMES: Record<RoleName, string> = {
  administrateur: '/pilotage',
  cme: '/pilotage',
  direction: '/pilotage',
  entreprise: '/entreprise',
  apprenant: '/ma-journee',
}

/** Permissions réelles des rôles (RolesAndPermissionsSeeder), limitées à celles qui guident la navigation. */
const PERMISSIONS: Record<RoleName, string[]> = {
  administrateur: ['companies.manage', 'companies.profiles.manage', 'learners.view', 'learners.update', 'learners.import', 'users.manage', 'referentials.manage', 'audit.view'],
  cme: ['companies.profiles.manage', 'learners.view', 'learners.competences.manage', 'referentials.manage'],
  direction: ['learners.view'],
  entreprise: [],
  apprenant: [],
}

function user(role: RoleName): CurrentUser {
  return {
    id: 1, first_name: 'Prénom', last_name: 'Fictif', email: null, username: 'compte-fictif',
    role, role_label: role, permissions: PERMISSIONS[role], home: HOMES[role],
  }
}

function Location() {
  return <p data-testid="location">{useLocation().pathname}</p>
}

function renderAs(role: RoleName, path = HOMES[role]) {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input)
      if (url === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
      if (url === '/api/v1/me') {
        return new Response(JSON.stringify({ data: user(role) }), { status: 200, headers: { 'Content-Type': 'application/json' } })
      }
      throw new Error(`Appel inattendu : ${url}`)
    }),
  )
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <AppRoutes />
        <Location />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function navLinks(): Promise<string[]> {
  const nav = await screen.findByRole('navigation', { name: 'Navigation principale' })
  return within(nav).getAllByRole('link').map((link) => link.textContent ?? '')
}

describe('Navigation par rôle', () => {
  it.each([
    ['administrateur', ['Tableau de bord', 'Entreprises participantes', 'Apprenants', 'Import des apprenants', 'Comptes CME et Direction', 'Référentiels', 'Journal d’audit']],
    ['cme', ['Tableau de bord', 'Entreprises participantes', 'Apprenants', 'Référentiels']],
    ['direction', ['Tableau de bord', 'Apprenants']],
    ['entreprise', ['Accueil', 'Profil entreprise']],
    ['apprenant', ['Ma journée']],
  ] as [RoleName, string[]][])('%s : liens dans l’ordre des maquettes, écrans existants seulement', async (role, expected) => {
    renderAs(role)
    expect(await navLinks()).toEqual(expected)
  })

  it('le lien actif est signalé ; « JobDating » ramène à l’accueil', async () => {
    renderAs('administrateur', '/profil')
    expect(await screen.findByRole('heading', { name: 'Changer mon mot de passe' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Tableau de bord' })).not.toHaveAttribute('aria-current')

    fireEvent.click(screen.getAllByRole('link', { name: /JobDating/ })[0])
    expect(screen.getByTestId('location')).toHaveTextContent('/pilotage')
    expect(screen.getByRole('link', { name: 'Tableau de bord' })).toHaveAttribute('aria-current', 'page')
  })

  it('administrateur, CME, Direction : « Mon profil » et déconnexion en bas de la barre', async () => {
    renderAs('direction')
    fireEvent.click(await screen.findByRole('link', { name: 'Mon profil' }))
    expect(screen.getByTestId('location')).toHaveTextContent('/profil')
    expect(screen.getByRole('button', { name: 'Se déconnecter' })).toBeInTheDocument()
  })

  it('mobile : le bouton ☰ ouvre la barre latérale, qui se referme après un clic sur un lien ou Échap', async () => {
    renderAs('cme')
    const open = await screen.findByRole('button', { name: 'Ouvrir le menu' })
    expect(open).toHaveAttribute('aria-expanded', 'false')

    fireEvent.click(open)
    expect(open).toHaveAttribute('aria-expanded', 'true')
    fireEvent.click(screen.getByRole('link', { name: 'Référentiels' }))
    expect(screen.getByTestId('location')).toHaveTextContent('/referentiels')
    expect(open).toHaveAttribute('aria-expanded', 'false')

    fireEvent.click(open)
    fireEvent.keyDown(open, { key: 'Escape' })
    expect(open).toHaveAttribute('aria-expanded', 'false')

    fireEvent.click(open)
    fireEvent.click(screen.getByRole('button', { name: 'Fermer le menu' }))
    expect(open).toHaveAttribute('aria-expanded', 'false')
  })

  it('entreprise : déconnexion en haut (mobile) et en bas de la barre (ordinateur), sans lien de gestion', async () => {
    renderAs('entreprise')
    await navLinks()
    expect(screen.getAllByRole('button', { name: 'Se déconnecter' })).toHaveLength(2)
    expect(screen.queryByRole('link', { name: 'Entreprises participantes' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ouvrir le menu' })).not.toBeInTheDocument()
  })

  it('apprenant : menu du compte fermé par défaut, avec « Mon profil » et la déconnexion', async () => {
    renderAs('apprenant')
    const menu = await screen.findByRole('button', { name: 'Menu du compte' })
    expect(menu).toHaveAttribute('aria-expanded', 'false')
    expect(screen.queryByRole('link', { name: 'Mon profil' })).not.toBeInTheDocument()

    fireEvent.click(menu)
    expect(menu).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByRole('button', { name: 'Se déconnecter' })).toBeInTheDocument()
    fireEvent.keyDown(menu, { key: 'Escape' })
    expect(menu).toHaveAttribute('aria-expanded', 'false')

    fireEvent.click(menu)
    fireEvent.click(screen.getByRole('link', { name: 'Mon profil' }))
    expect(screen.getByTestId('location')).toHaveTextContent('/profil')
    expect(menu).toHaveAttribute('aria-expanded', 'false')

    // Retour à « Ma journée » : le menu reste fermé (revue Codex navigation, constat 2).
    fireEvent.click(screen.getByRole('link', { name: 'Ma journée' }))
    expect(screen.getByTestId('location')).toHaveTextContent('/ma-journee')
    expect(menu).toHaveAttribute('aria-expanded', 'false')
    expect(screen.queryByRole('link', { name: 'Mon profil' })).not.toBeInTheDocument()
  })

  // Échec réseau de /logout (le faux serveur refuse tout appel inattendu) : le menu ouvert se
  // referme pour laisser lire l'erreur et le focus revient à son bouton (constat 1).
  it.each([
    ['cme', 'Ouvrir le menu'],
    ['apprenant', 'Menu du compte'],
  ] as [RoleName, string][])('%s : déconnexion échouée avec le menu ouvert, menu refermé et erreur lisible', async (role, trigger) => {
    renderAs(role)
    const button = await screen.findByRole('button', { name: trigger })
    fireEvent.click(button)
    expect(button).toHaveAttribute('aria-expanded', 'true')

    fireEvent.click(screen.getByRole('button', { name: 'Se déconnecter' }))
    expect(await screen.findByText(LOGOUT_FAILED_MESSAGE)).toBeInTheDocument()
    expect(button).toHaveAttribute('aria-expanded', 'false')
    expect(button).toHaveFocus()
    expect(screen.getByTestId('location')).toHaveTextContent(HOMES[role])
  })
})
