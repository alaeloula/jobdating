import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import AppRoutes from '../AppRoutes'
import type { CurrentUser, RoleName } from '../auth/types'

const TOKEN = 'A'.repeat(64)
const PASSWORD = 'Kq7#mZ2vRt-nouveau'

const HOMES: Record<RoleName, string> = {
  administrateur: '/pilotage',
  cme: '/pilotage',
  direction: '/pilotage',
  entreprise: '/entreprise',
  apprenant: '/ma-journee',
}

function user(role: RoleName, firstName = 'Prénom'): CurrentUser {
  return {
    id: role === 'cme' ? 7 : 1,
    first_name: firstName,
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

interface Call {
  method: string
  url: string
  body: Record<string, unknown> | undefined
}

/** fetch simulé : appels enregistrés ; `/me` répond selon `me()` ; le reste par le gestionnaire. */
function mockApi(me: () => CurrentUser | null, handler: (call: Call) => Response | undefined) {
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
      if (call.url === '/api/v1/me') {
        const current = me()
        return current ? json(200, { data: current }) : json(401, { message: 'x', code: 'unauthenticated' })
      }
      const response = handler(call)
      if (response) return response
      throw new Error(`Appel inattendu : ${call.method} ${call.url}`)
    }),
  )
  return calls
}

function LocationProbe() {
  const location = useLocation()
  return <output data-testid="location">{`${location.pathname}${location.search}${location.hash}`}</output>
}

function renderAt(path: string) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <AppRoutes />
        <LocationProbe />
      </MemoryRouter>
    </QueryClientProvider>,
  )
  return queryClient
}

/** Le cache TanStack (requêtes et envois) contient-il le jeton ou un mot de passe ? */
function cacheContainsSecret(queryClient: QueryClient): boolean {
  const content = JSON.stringify([
    queryClient.getQueryCache().getAll().map((query) => [query.queryKey, query.state.data ?? null]),
    queryClient.getMutationCache().getAll().map((mutation) => [mutation.state.variables ?? null, mutation.state.data ?? null]),
  ])
  return content.includes(TOKEN) || content.includes(PASSWORD)
}

async function fillNewPassword(password = PASSWORD, confirmation = password) {
  fireEvent.change(await screen.findByLabelText('Nouveau mot de passe'), { target: { value: password } })
  fireEvent.change(screen.getByLabelText('Confirmer le mot de passe'), { target: { value: confirmation } })
}

describe('Mot de passe oublié', () => {
  it("le lien de l'écran de connexion mène au formulaire ; même message pour toute adresse", async () => {
    const calls = mockApi(
      () => null,
      (call) =>
        call.url === '/api/v1/password/forgot'
          ? json(202, { message: 'Si un compte correspond à cette adresse, un e-mail vient d’être envoyé.' })
          : undefined,
    )
    renderAt('/login')

    fireEvent.click(await screen.findByRole('link', { name: 'Mot de passe oublié ?' }))
    expect(await screen.findByRole('heading', { name: 'Mot de passe oublié' })).toBeInTheDocument()
    expect(screen.getByText(/Entreprise : en cas d'oubli/)).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Adresse e-mail'), { target: { value: 'eleve@example.test' } })
    fireEvent.click(screen.getByRole('button', { name: 'Recevoir le lien' }))

    expect(await screen.findByText('Si un compte correspond à cette adresse, un e-mail vient d’être envoyé.')).toHaveAttribute('role', 'status')
    expect(calls.find((c) => c.url === '/api/v1/password/forgot')?.body).toEqual({ email: 'eleve@example.test' })
  })

  it('affiche le délai quand la limite est atteinte', async () => {
    mockApi(
      () => null,
      (call) =>
        call.url === '/api/v1/password/forgot'
          ? json(429, { message: 'Trop de demandes. Réessayez dans 40 secondes.', code: 'too_many_requests', retry_after: 40 })
          : undefined,
    )
    renderAt('/mot-de-passe-oublie')

    fireEvent.change(await screen.findByLabelText('Adresse e-mail'), { target: { value: 'eleve@example.test' } })
    fireEvent.click(screen.getByRole('button', { name: 'Recevoir le lien' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Réessayez dans 40 secondes.')
  })

  it("compte non activé à la connexion : proposition d'un nouveau lien d'activation", async () => {
    mockApi(
      () => null,
      (call) =>
        call.url === '/api/v1/login'
          ? json(403, { message: "Votre compte n'est pas encore activé.", code: 'account_not_activated' })
          : undefined,
    )
    renderAt('/login')

    fireEvent.change(await screen.findByLabelText("E-mail ou nom d'utilisateur"), { target: { value: 'cme@example.test' } })
    fireEvent.change(screen.getByLabelText('Mot de passe'), { target: { value: 'x' } })
    fireEvent.click(screen.getByRole('button', { name: 'Se connecter' }))

    fireEvent.click(await screen.findByRole('link', { name: 'Recevoir un nouveau lien d’activation' }))
    expect(await screen.findByText('Ouvrez à nouveau le lien reçu par e-mail.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Recevoir un nouveau lien' })).toBeInTheDocument()
  })
})

describe('Activer mon compte', () => {
  it('jeton lu dans le fragment, retiré de l’adresse, jamais dans une adresse d’API ; réessai après 422 ; accueil du rôle', async () => {
    let attempts = 0
    let activated = false
    const calls = mockApi(
      () => (activated ? user('cme', 'Samira') : null),
      (call) => {
        if (call.url === '/api/v1/activation/check') return json(200, { data: { first_name: 'Samira' } })
        if (call.url === '/api/v1/activation') {
          attempts += 1
          if (attempts === 1) {
            return json(422, { message: 'x', errors: { password: ['Ce mot de passe est trop courant. Choisissez-en un autre.'] } })
          }
          activated = true
          return json(200, { data: user('cme', 'Samira') })
        }
        return undefined
      },
    )
    const queryClient = renderAt(`/activation#${TOKEN}`)

    expect(await screen.findByText(/Bonjour Samira/)).toBeInTheDocument()
    expect(screen.getByTestId('location')).toHaveTextContent(/^\/activation$/)

    await fillNewPassword()
    fireEvent.click(screen.getByRole('button', { name: 'Activer mon compte' }))
    expect(await screen.findByText('Ce mot de passe est trop courant. Choisissez-en un autre.')).toBeInTheDocument()

    // Même lien, sans rouvrir l'e-mail.
    fireEvent.click(screen.getByRole('button', { name: 'Activer mon compte' }))
    expect(await screen.findByRole('heading', { name: 'Tableau de bord de pilotage' })).toBeInTheDocument()

    const posts = calls.filter((c) => c.url === '/api/v1/activation')
    expect(posts).toHaveLength(2)
    expect(posts.every((c) => c.body?.token === TOKEN)).toBe(true)
    expect(calls.some((c) => c.url.includes(TOKEN))).toBe(false)
    expect(cacheContainsSecret(queryClient)).toBe(false)
  })

  it('lien invalide (410) : message et formulaire de nouveau lien', async () => {
    mockApi(
      () => null,
      (call) => (call.url === '/api/v1/activation/check' ? json(410, { message: 'x', code: 'link_invalid' }) : undefined),
    )
    renderAt(`/activation#${TOKEN}`)

    expect(await screen.findByText('Ce lien n’est plus valable. Il a expiré ou a déjà été utilisé.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Recevoir un nouveau lien' })).toBeInTheDocument()
  })

  it('rechargement sans fragment : invitation à rouvrir le lien, aucun appel de vérification', async () => {
    const calls = mockApi(() => null, () => undefined)
    renderAt('/activation')

    expect(await screen.findByText('Ouvrez à nouveau le lien reçu par e-mail.')).toBeInTheDocument()
    expect(calls.some((c) => c.url === '/api/v1/activation/check')).toBe(false)
  })

  it('erreur réseau puis 429 : le lien est conservé et le réessai fonctionne', async () => {
    let attempts = 0
    mockApi(
      () => null,
      (call) => {
        if (call.url === '/api/v1/activation/check') return json(200, { data: { first_name: 'Samira' } })
        if (call.url === '/api/v1/activation') {
          attempts += 1
          if (attempts === 1) throw new TypeError('Failed to fetch')
          if (attempts === 2) return json(429, { message: 'Trop de demandes. Réessayez dans 30 secondes.', code: 'too_many_requests' })
          return json(200, { data: user('cme') })
        }
        return undefined
      },
    )
    renderAt(`/activation#${TOKEN}`)

    await fillNewPassword()
    fireEvent.click(await screen.findByRole('button', { name: 'Activer mon compte' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Connexion au serveur impossible')
    fireEvent.click(screen.getByRole('button', { name: 'Activer mon compte' }))
    expect(await screen.findByText('Trop de demandes. Réessayez dans 30 secondes.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Activer mon compte' }))
    expect(await screen.findByRole('heading', { name: 'Tableau de bord de pilotage' })).toBeInTheDocument()
  })

  it('les deux saisies doivent correspondre (contrôle avant envoi)', async () => {
    const calls = mockApi(
      () => null,
      (call) => (call.url === '/api/v1/activation/check' ? json(200, { data: { first_name: 'Samira' } }) : undefined),
    )
    renderAt(`/activation#${TOKEN}`)

    await fillNewPassword(PASSWORD, 'autre-chose-2026')
    fireEvent.click(await screen.findByRole('button', { name: 'Activer mon compte' }))

    expect(await screen.findByText('Les deux mots de passe ne correspondent pas.')).toBeInTheDocument()
    expect(calls.some((c) => c.url === '/api/v1/activation')).toBe(false)
  })
})

describe('Nouveau mot de passe', () => {
  it('autre compte connecté : bandeau, déconnexion puis réinitialisation, retour à la connexion avec message', async () => {
    let loggedIn = true
    const calls = mockApi(
      () => (loggedIn ? user('direction', 'Bachir') : null),
      (call) => {
        if (call.url === '/api/v1/password/reset/check') return json(200, { data: { valid: true } })
        if (call.url === '/api/v1/logout') {
          loggedIn = false
          return new Response(null, { status: 204 })
        }
        if (call.url === '/api/v1/password/reset') return json(200, { message: 'Mot de passe modifié. Vous pouvez vous connecter.' })
        return undefined
      },
    )
    const queryClient = renderAt(`/reinitialiser#${TOKEN}`)

    expect(await screen.findByText(/Vous êtes connecté en tant que Bachir Fictif\. Pour continuer, vous serez déconnecté\./)).toBeInTheDocument()

    await fillNewPassword()
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByRole('heading', { name: 'Connexion' })).toBeInTheDocument()
    expect(screen.getByText('Mot de passe modifié. Vous pouvez vous connecter.')).toBeInTheDocument()
    const order = calls.filter((c) => c.method === 'POST').map((c) => c.url)
    expect(order.indexOf('/api/v1/logout')).toBeLessThan(order.indexOf('/api/v1/password/reset'))
    expect(cacheContainsSecret(queryClient)).toBe(false)
  })

  it('lien invalide : lien vers une nouvelle demande', async () => {
    mockApi(
      () => null,
      (call) => (call.url === '/api/v1/password/reset/check' ? json(410, { message: 'x', code: 'link_invalid' }) : undefined),
    )
    renderAt(`/reinitialiser#${TOKEN}`)

    expect(await screen.findByRole('link', { name: 'Demander un nouveau lien' })).toBeInTheDocument()
  })
})

describe('Mon profil', () => {
  it.each(['administrateur', 'cme', 'direction', 'entreprise', 'apprenant'] as RoleName[])(
    'lien « Mon profil » pour le rôle %s',
    async (role) => {
      mockApi(() => user(role), () => undefined)
      renderAt(HOMES[role])

      fireEvent.click(await screen.findByRole('link', { name: 'Mon profil' }))
      expect(await screen.findByRole('heading', { name: 'Changer mon mot de passe' })).toBeInTheDocument()
    },
  )

  it('changement : mot de passe actuel faux puis succès ; rien dans le cache', async () => {
    let attempts = 0
    const calls = mockApi(
      () => user('entreprise'),
      (call) => {
        if (call.url === '/api/v1/me/password') {
          attempts += 1
          return attempts === 1
            ? json(422, { message: 'x', errors: { current_password: ['Mot de passe actuel incorrect.'] } })
            : new Response(null, { status: 204 })
        }
        return undefined
      },
    )
    const queryClient = renderAt('/profil')

    fireEvent.change(await screen.findByLabelText('Mot de passe actuel'), { target: { value: 'ancien-mot-2026' } })
    fireEvent.change(screen.getByLabelText('Nouveau mot de passe'), { target: { value: PASSWORD } })
    fireEvent.change(screen.getByLabelText('Confirmer le nouveau mot de passe'), { target: { value: PASSWORD } })
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))
    expect(await screen.findByText('Mot de passe actuel incorrect.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))
    expect(await screen.findByText('Mot de passe modifié. Les autres recruteurs connectés à ce compte restent connectés.')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByLabelText('Nouveau mot de passe')).toHaveValue(''))

    expect(calls.filter((c) => c.method === 'PUT').at(-1)?.body).toEqual({
      current_password: 'ancien-mot-2026',
      password: PASSWORD,
      password_confirmation: PASSWORD,
    })
    expect(cacheContainsSecret(queryClient)).toBe(false)
    expect(screen.queryByText(/ferme les sessions/)).not.toBeInTheDocument()
  })
})
