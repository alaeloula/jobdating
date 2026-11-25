import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import AppRoutes from '../AppRoutes'
import { apiRequest } from '../lib/api'
import type { CurrentUser, RoleName } from './types'

const HOMES: Record<RoleName, string> = {
  administrateur: '/pilotage',
  cme: '/pilotage',
  direction: '/pilotage',
  entreprise: '/entreprise',
  apprenant: '/ma-journee',
}

function fakeUser(role: RoleName): CurrentUser {
  return {
    id: 1,
    first_name: 'Prénom',
    last_name: 'Fictif',
    email: role === 'entreprise' ? null : 'compte@example.test',
    username: role === 'entreprise' ? 'societe-fictive' : null,
    role,
    role_label: role,
    permissions: [],
    home: HOMES[role],
  }
}

function json(status: number, body?: unknown): Response {
  return new Response(body === undefined ? null : JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

type Handler = (url: string, init?: RequestInit) => Response

/** Remplace fetch : chaque appel est routé vers le gestionnaire de l'URL. */
function mockFetch(handlers: Record<string, Handler>) {
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    const handler = handlers[url]
    if (!handler) throw new Error(`Appel inattendu : ${url}`)
    return handler(url, init)
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

/** Affiche l'adresse courante, pour vérifier les redirections (chemin et paramètres). */
function LocationProbe() {
  const location = useLocation()
  return <output data-testid="location">{`${location.pathname}${location.search}`}</output>
}

function renderApp(initialPath: string) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const result = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialPath]}>
        <AppRoutes />
        <LocationProbe />
      </MemoryRouter>
    </QueryClientProvider>,
  )
  return { ...result, queryClient }
}

/** Le cache des mutations contient-il encore le mot de passe saisi ou l'identité renvoyée ? */
function mutationsRetain(queryClient: QueryClient, password: string): boolean {
  return queryClient
    .getMutationCache()
    .getAll()
    .some((mutation) => {
      const content = JSON.stringify([mutation.state.variables ?? null, mutation.state.data ?? null])
      return content.includes(password) || content.includes('compte@example.test')
    })
}

async function fillAndSubmit(identifier: string, password: string) {
  fireEvent.change(await screen.findByLabelText("E-mail ou nom d'utilisateur"), { target: { value: identifier } })
  fireEvent.change(screen.getByLabelText('Mot de passe'), { target: { value: password } })
  fireEvent.click(screen.getByRole('button', { name: 'Se connecter' }))
}

describe('Connexion', () => {
  it('affiche les messages de validation sans appeler le serveur pour la connexion', async () => {
    const fetchMock = mockFetch({ '/api/v1/me': () => json(401, { message: 'x', code: 'unauthenticated' }) })
    renderApp('/login')

    fireEvent.click(await screen.findByRole('button', { name: 'Se connecter' }))

    expect(await screen.findByText("Saisissez votre e-mail ou votre nom d'utilisateur.")).toBeInTheDocument()
    expect(screen.getByText('Saisissez votre mot de passe.')).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalledWith('/api/v1/login', expect.anything())
  })

  it("dirige l'entreprise vers son tableau de bord après connexion", async () => {
    const fetchMock = mockFetch({
      '/api/v1/me': () => json(401, { message: 'x', code: 'unauthenticated' }),
      '/sanctum/csrf-cookie': () => new Response(null, { status: 204 }),
      '/api/v1/login': () => json(200, { data: fakeUser('entreprise') }),
      '/api/v1/me/company-event': () => json(200, { data: null }),
    })
    renderApp('/login')

    await fillAndSubmit('societe-fictive', 'mot-de-passe-fictif')

    expect(await screen.findByRole('heading', { name: /^Bonjour/ })).toBeInTheDocument()
    const loginCall = fetchMock.mock.calls.find(([url]) => url === '/api/v1/login')
    expect(JSON.parse(String(loginCall?.[1]?.body))).toEqual({
      identifier: 'societe-fictive',
      password: 'mot-de-passe-fictif',
    })
  })

  it('affiche le message générique du serveur', async () => {
    mockFetch({
      '/api/v1/me': () => json(401, { message: 'x', code: 'unauthenticated' }),
      '/sanctum/csrf-cookie': () => new Response(null, { status: 204 }),
      '/api/v1/login': () => json(422, { message: 'Identifiants incorrects.', code: 'invalid_credentials' }),
    })
    renderApp('/login')

    await fillAndSubmit('societe-fictive', 'mauvais')

    expect(await screen.findByRole('alert')).toHaveTextContent('Identifiants incorrects.')
  })

  it("affiche le délai d'attente quand les tentatives sont dépassées", async () => {
    mockFetch({
      '/api/v1/me': () => json(401, { message: 'x', code: 'unauthenticated' }),
      '/sanctum/csrf-cookie': () => new Response(null, { status: 204 }),
      '/api/v1/login': () =>
        json(429, {
          message: 'Trop de tentatives de connexion. Réessayez dans 42 secondes.',
          code: 'too_many_attempts',
          retry_after: 42,
        }),
    })
    renderApp('/login')

    await fillAndSubmit('societe-fictive', 'mauvais')

    expect(await screen.findByRole('alert')).toHaveTextContent('Réessayez dans 42 secondes.')
  })

  it('un utilisateur connecté qui ouvre /login est dirigé vers son accueil', async () => {
    mockFetch({ '/api/v1/me': () => json(200, { data: fakeUser('apprenant') }) })
    renderApp('/login')

    expect(await screen.findByRole('heading', { name: 'Ma journée' })).toBeInTheDocument()
  })
})

describe('Gardes de route', () => {
  it('renvoie à la connexion puis à la page demandée', async () => {
    mockFetch({
      '/api/v1/me': () => json(401, { message: 'x', code: 'unauthenticated' }),
      '/sanctum/csrf-cookie': () => new Response(null, { status: 204 }),
      '/api/v1/login': () => json(200, { data: fakeUser('cme') }),
    })
    renderApp('/pilotage')

    expect(await screen.findByRole('heading', { name: 'Connexion' })).toBeInTheDocument()

    await fillAndSubmit('compte@example.test', 'mot-de-passe-fictif')

    expect(await screen.findByRole('heading', { name: 'Tableau de bord de pilotage' })).toBeInTheDocument()
  })

  it("renvoie vers l'accueil du rôle avec un message pour une page non autorisée", async () => {
    mockFetch({ '/api/v1/me': () => json(200, { data: fakeUser('apprenant') }) })
    renderApp('/pilotage')

    expect(await screen.findByRole('heading', { name: 'Ma journée' })).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent("Vous n'avez pas accès à cette page.")
  })

  it('la Direction arrive sur le pilotage en lecture seule', async () => {
    mockFetch({ '/api/v1/me': () => json(200, { data: fakeUser('direction') }) })
    renderApp('/')

    expect(await screen.findByRole('heading', { name: 'Tableau de bord de pilotage' })).toBeInTheDocument()
    expect(screen.getByText('Lecture seule')).toBeInTheDocument()
  })

  it('la déconnexion ramène à la connexion', async () => {
    let loggedIn = true
    mockFetch({
      '/api/v1/me': () => (loggedIn ? json(200, { data: fakeUser('entreprise') }) : json(401, {})),
      '/sanctum/csrf-cookie': () => new Response(null, { status: 204 }),
      '/api/v1/logout': () => {
        loggedIn = false
        return new Response(null, { status: 204 })
      },
    })
    renderApp('/entreprise')

    fireEvent.click((await screen.findAllByRole('button', { name: 'Se déconnecter' }))[0])

    expect(await screen.findByRole('heading', { name: 'Connexion' })).toBeInTheDocument()
    expect(screen.getByTestId('location')).toHaveTextContent(/^\/login$/)
  })

  // Régression (revue Codex T-04, constat 2).
  it("une déconnexion qui échoue (réseau) n'est pas présentée comme réussie", async () => {
    mockFetch({
      '/api/v1/me': () => json(200, { data: fakeUser('entreprise') }),
      '/sanctum/csrf-cookie': () => new Response(null, { status: 204 }),
      '/api/v1/me/company-event': () => json(200, { data: null }),
      '/api/v1/logout': () => {
        throw new TypeError('Failed to fetch')
      },
    })
    renderApp('/entreprise')

    fireEvent.click((await screen.findAllByRole('button', { name: 'Se déconnecter' }))[0])

    expect(await screen.findByRole('alert')).toHaveTextContent('La déconnexion a échoué')
    expect(screen.getByRole('heading', { name: /^Bonjour/ })).toBeInTheDocument()
    expect(screen.getByTestId('location')).toHaveTextContent('/entreprise')
  })

  it('une déconnexion sur une session déjà fermée (401) est effective', async () => {
    mockFetch({
      '/api/v1/me': () => json(200, { data: fakeUser('entreprise') }),
      '/sanctum/csrf-cookie': () => new Response(null, { status: 204 }),
      '/api/v1/logout': () => json(401, { message: 'x', code: 'unauthenticated' }),
    })
    renderApp('/entreprise')

    fireEvent.click((await screen.findAllByRole('button', { name: 'Se déconnecter' }))[0])

    expect(await screen.findByRole('heading', { name: 'Connexion' })).toBeInTheDocument()
  })

  // Régression (revue Codex T-04, constat 4).
  it('le retour après connexion conserve les paramètres de la page demandée', async () => {
    mockFetch({
      '/api/v1/me': () => json(401, { message: 'x', code: 'unauthenticated' }),
      '/sanctum/csrf-cookie': () => new Response(null, { status: 204 }),
      '/api/v1/login': () => json(200, { data: fakeUser('cme') }),
    })
    renderApp('/pilotage?filtre=exemple')

    await fillAndSubmit('compte@example.test', 'mot-de-passe-fictif')

    expect(await screen.findByRole('heading', { name: 'Tableau de bord de pilotage' })).toBeInTheDocument()
    expect(screen.getByTestId('location')).toHaveTextContent('/pilotage?filtre=exemple')
  })

  // Régression (contre-revue Codex T-04) : ni le mot de passe saisi ni l'identité ne restent
  // dans le cache des mutations, après la connexion puis après une déconnexion (204 ou 401).
  it.each([204, 401])(
    'aucun mot de passe ni identité ne reste en mémoire (déconnexion %i)',
    async (logoutStatus) => {
      const password = 'mot-de-passe-fictif-unique'
      mockFetch({
        '/api/v1/me': () => json(401, { message: 'x', code: 'unauthenticated' }),
        '/sanctum/csrf-cookie': () => new Response(null, { status: 204 }),
        '/api/v1/login': () => json(200, { data: fakeUser('cme') }),
        '/api/v1/logout': () =>
          logoutStatus === 204 ? new Response(null, { status: 204 }) : json(401, { message: 'x' }),
      })
      const { queryClient } = renderApp('/login')

      await fillAndSubmit('compte@example.test', password)
      expect(await screen.findByRole('heading', { name: 'Tableau de bord de pilotage' })).toBeInTheDocument()

      // Après la connexion : l'écran de connexion est fermé, son envoi n'est plus conservé.
      await waitFor(() => expect(mutationsRetain(queryClient, password)).toBe(false))

      fireEvent.click(screen.getAllByRole('button', { name: 'Se déconnecter' })[0])
      expect(await screen.findByRole('heading', { name: 'Connexion' })).toBeInTheDocument()

      await waitFor(() => {
        expect(mutationsRetain(queryClient, password)).toBe(false)
        expect(queryClient.getMutationCache().getAll()).toHaveLength(0)
      })
    },
  )

  it('après une déconnexion, le compte suivant arrive sur son propre accueil', async () => {
    let current: CurrentUser | null = fakeUser('entreprise')
    mockFetch({
      '/api/v1/me': () => (current ? json(200, { data: current }) : json(401, {})),
      '/sanctum/csrf-cookie': () => new Response(null, { status: 204 }),
      '/api/v1/logout': () => {
        current = null
        return new Response(null, { status: 204 })
      },
      '/api/v1/login': () => {
        current = fakeUser('apprenant')
        return json(200, { data: current })
      },
    })
    renderApp('/entreprise')

    fireEvent.click((await screen.findAllByRole('button', { name: 'Se déconnecter' }))[0])
    await fillAndSubmit('compte@example.test', 'mot-de-passe-fictif')

    expect(await screen.findByRole('heading', { name: 'Ma journée' })).toBeInTheDocument()
    expect(screen.queryByText("Vous n'avez pas accès à cette page.")).not.toBeInTheDocument()
  })
})

describe('Client API', () => {
  it('rejoue une fois la requête après un jeton CSRF expiré (419)', async () => {
    let loginCalls = 0
    const fetchMock = mockFetch({
      '/sanctum/csrf-cookie': () => new Response(null, { status: 204 }),
      '/api/v1/login': () => {
        loginCalls += 1
        return loginCalls === 1 ? json(419, { message: 'La page a expiré.' }) : json(200, { data: fakeUser('cme') })
      },
    })

    const result = await apiRequest<{ data: CurrentUser }>('/login', { method: 'POST', body: {}, silent: true })

    expect(result.data.role).toBe('cme')
    expect(loginCalls).toBe(2)
    await waitFor(() =>
      expect(fetchMock.mock.calls.filter(([url]) => url === '/sanctum/csrf-cookie').length).toBeGreaterThanOrEqual(1),
    )
  })
})
