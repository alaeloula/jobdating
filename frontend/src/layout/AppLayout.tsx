import { useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import { Link, NavLink, Outlet, useLocation } from 'react-router'
import { useLogout, useMe } from '../auth/queries'
import type { CurrentUser, FlashLocationState, RoleName } from '../auth/types'
import { CloseIcon, HomeIcon, LogoutIcon, MenuIcon, UserIcon } from './icons'

/** Permissions des liens de navigation (CDC 3.2). Le serveur reste seul juge des droits. */
const AUDIT_VIEW = 'audit.view'
const REFERENTIALS_MANAGE = 'referentials.manage'
const USERS_MANAGE = 'users.manage'
const LEARNERS_VIEW = 'learners.view'
const COMPANIES_PERMISSIONS = ['companies.manage', 'companies.profiles.manage']

export const LOGOUT_FAILED_MESSAGE =
  "La déconnexion a échoué : votre session est peut-être encore ouverte. Vérifiez votre connexion et réessayez."

interface NavItem {
  to: string
  label: string
  icon?: (props: { className?: string }) => ReactNode
  end?: boolean
}

/**
 * Liens par rôle, dans l'ordre des maquettes (admin-01, entreprise-ordi-01, apprenant-01) et
 * avec leurs libellés. Seuls les écrans déjà développés apparaissent : chaque tâche ajoute le sien.
 */
function navItems(user: CurrentUser): NavItem[] {
  const can = (permission: string) => user.permissions.includes(permission)

  if (user.role === 'entreprise') {
    return [
      { to: user.home, label: 'Accueil', icon: HomeIcon, end: true },
      { to: '/profil', label: 'Profil entreprise', icon: UserIcon },
    ]
  }
  if (user.role === 'apprenant') {
    return [{ to: user.home, label: 'Ma journée', icon: HomeIcon, end: true }]
  }

  const items: NavItem[] = [{ to: user.home, label: 'Tableau de bord', end: true }]
  if (COMPANIES_PERMISSIONS.some(can)) items.push({ to: '/entreprises', label: 'Entreprises participantes' })
  if (can(LEARNERS_VIEW)) items.push({ to: '/apprenants', label: 'Apprenants' })
  if (can(USERS_MANAGE)) items.push({ to: '/comptes', label: 'Comptes CME et Direction' })
  if (can(REFERENTIALS_MANAGE)) items.push({ to: '/referentiels', label: 'Référentiels' })
  if (can(AUDIT_VIEW)) items.push({ to: '/journal-audit', label: 'Journal d’audit' })
  return items
}

const SPACE_LABEL: Record<RoleName, string> = {
  administrateur: 'Administration',
  cme: 'Espace CME',
  direction: 'Espace Direction',
  entreprise: 'Espace entreprise',
  apprenant: 'Espace apprenant',
}

function displayName(user: CurrentUser): string {
  return `${user.first_name} ${user.last_name}`.trim()
}

function initials(user: CurrentUser): string {
  return displayName(user)
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => word[0]?.toUpperCase())
    .join('')
}

function Avatar({ user }: { user: CurrentUser }) {
  return (
    <span aria-hidden="true" className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-sm font-bold text-indigo-800">
      {initials(user)}
    </span>
  )
}

function Brand({ user, onClick }: { user: CurrentUser; onClick?: () => void }) {
  return (
    <Link to={user.home} onClick={onClick} className="block rounded-lg focus-visible:outline-2 focus-visible:outline-indigo-600">
      <span className="block text-xl font-bold text-indigo-800">JobDating</span>
      <span className={`block text-sm ${user.role === 'administrateur' ? 'font-semibold text-indigo-800' : 'text-slate-600'}`}>
        {SPACE_LABEL[user.role]}
      </span>
    </Link>
  )
}

/** Mise en page commune des espaces connectés : navigation par rôle, identité, déconnexion. */
export default function AppLayout() {
  const { data: user } = useMe()
  const logout = useLogout()
  const location = useLocation()
  const flash = (location.state as FlashLocationState | null)?.flash
  // Menu mobile (☰) et menu du compte : refermés à chaque changement de page, retour par
  // l'historique compris (revue Codex navigation, constat 2 : plus de réouverture au retour).
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [accountMenuOpen, setAccountMenuOpen] = useState(false)
  const [shownPath, setShownPath] = useState(location.pathname)
  if (shownPath !== location.pathname) {
    setShownPath(location.pathname)
    setDrawerOpen(false)
    setAccountMenuOpen(false)
  }
  const drawerButton = useRef<HTMLButtonElement>(null)
  const accountButton = useRef<HTMLButtonElement>(null)

  // Après une déconnexion confirmée, l'utilisateur devient null et la garde de route
  // ramène à l'écran de connexion. En cas d'échec, on reste ici avec un message.
  // Échec : le menu ouvert est refermé pour que le message d'erreur reste lisible, et le focus
  // revient au bouton qui l'avait ouvert (revue Codex navigation, constat 1).
  const handleLogout = () =>
    logout.mutate(undefined, {
      onError: () => {
        if (drawerOpen) drawerButton.current?.focus()
        if (accountMenuOpen) accountButton.current?.focus()
        setDrawerOpen(false)
        setAccountMenuOpen(false)
      },
    })
  const logoutLabel = logout.isPending ? 'Déconnexion…' : 'Se déconnecter'

  const closeOnEscape = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
      setDrawerOpen(false)
      setAccountMenuOpen(false)
    }
  }

  const messages = (
    <>
      {logout.isError && (
        <p role="alert" className="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-red-800">
          {LOGOUT_FAILED_MESSAGE}
        </p>
      )}
      {flash && (
        <p role="alert" className="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-amber-900">
          {flash}
        </p>
      )}
    </>
  )

  if (!user) {
    return (
      <div className="min-h-screen bg-slate-100 text-slate-900">
        <main className="mx-auto max-w-5xl px-4 py-6">
          {messages}
          <Outlet />
        </main>
      </div>
    )
  }

  const items = navItems(user)

  // ------------------------------------------------ Apprenant : onglets en bas (apprenant-01)
  if (user.role === 'apprenant') {
    return (
      <div className="min-h-screen bg-slate-100 pb-20 text-slate-900" onKeyDown={closeOnEscape}>
        <header className="border-b border-slate-200 bg-white">
          <div className="mx-auto flex max-w-3xl items-center justify-between gap-3 px-4 py-3">
            <Brand user={user} />
            <div className="relative">
              <button
                type="button"
                ref={accountButton}
                aria-label="Menu du compte"
                aria-expanded={accountMenuOpen}
                aria-controls="menu-compte"
                onClick={() => setAccountMenuOpen(!accountMenuOpen)}
                className="rounded-full focus-visible:outline-2 focus-visible:outline-indigo-600"
              >
                <Avatar user={user} />
              </button>
              {accountMenuOpen && (
                <div id="menu-compte" className="absolute right-0 z-20 mt-2 w-56 rounded-xl border border-slate-200 bg-white p-2 shadow-lg">
                  <p className="px-3 py-2 text-sm font-semibold">{displayName(user)}</p>
                  <Link to="/profil" className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-slate-100">
                    <UserIcon className="h-4 w-4" />
                    Mon profil
                  </Link>
                  <button
                    type="button"
                    onClick={handleLogout}
                    disabled={logout.isPending}
                    className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-100 disabled:opacity-60"
                  >
                    <LogoutIcon className="h-4 w-4" />
                    {logoutLabel}
                  </button>
                </div>
              )}
            </div>
          </div>
        </header>
        <main className="mx-auto max-w-3xl px-4 py-6">
          {messages}
          <Outlet />
        </main>
        <nav aria-label="Navigation principale" className="fixed inset-x-0 bottom-0 z-10 border-t border-slate-200 bg-white">
          <ul className="mx-auto flex max-w-3xl justify-around">
            {items.map((item) => (
              <li key={item.to} className="flex-1">
                <TabLink item={item} />
              </li>
            ))}
          </ul>
        </nav>
      </div>
    )
  }

  // ------------------------------------------------ Entreprise : barre latérale sur ordinateur
  // (entreprise-ordi-01), onglets en bas sur mobile (entreprise-01). Une seule navigation.
  if (user.role === 'entreprise') {
    return (
      <div className="min-h-screen bg-slate-100 pb-20 text-slate-900 md:flex md:pb-0">
        <header className="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3 md:hidden">
          <Brand user={user} />
          <button
            type="button"
            aria-label={logoutLabel}
            onClick={handleLogout}
            disabled={logout.isPending}
            className="rounded-xl bg-slate-100 p-3 text-slate-700 hover:bg-slate-200 disabled:opacity-60"
          >
            <LogoutIcon />
          </button>
        </header>
        <aside className="contents md:sticky md:top-0 md:flex md:h-screen md:w-72 md:shrink-0 md:flex-col md:border-r md:border-slate-200 md:bg-white md:p-5">
          <div className="hidden md:block">
            <Brand user={user} />
          </div>
          <nav
            aria-label="Navigation principale"
            className="fixed inset-x-0 bottom-0 z-10 border-t border-slate-200 bg-white md:static md:mt-8 md:border-0 md:bg-transparent"
          >
            <ul className="flex justify-around md:flex-col md:gap-1">
              {items.map((item) => (
                <li key={item.to} className="flex-1 md:flex-none">
                  <EntrepriseLink item={item} />
                </li>
              ))}
            </ul>
          </nav>
          <div className="mt-auto hidden items-center gap-3 border-t border-slate-200 pt-4 md:flex">
            <Avatar user={user} />
            <div className="min-w-0">
              <p className="truncate font-semibold">{displayName(user)}</p>
              <button type="button" onClick={handleLogout} disabled={logout.isPending} className="text-sm text-indigo-700 underline hover:text-indigo-900 disabled:opacity-60">
                {logoutLabel}
              </button>
            </div>
          </div>
        </aside>
        <main className="min-w-0 flex-1 px-4 py-6 md:px-8">
          <div className="mx-auto max-w-5xl">
            {messages}
            <Outlet />
          </div>
        </main>
      </div>
    )
  }

  // ------------------------------------------------ Administrateur, CME, Direction : barre
  // latérale (admin-01) ; sur mobile, elle s'ouvre par le bouton ☰.
  const closeDrawer = () => setDrawerOpen(false)
  return (
    <div className="min-h-screen bg-slate-100 text-slate-900 md:flex" onKeyDown={closeOnEscape}>
      <header className="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3 md:hidden">
        <Brand user={user} />
        <button
          type="button"
          ref={drawerButton}
          aria-label="Ouvrir le menu"
          aria-expanded={drawerOpen}
          aria-controls="barre-laterale"
          onClick={() => setDrawerOpen(true)}
          className="rounded-xl bg-slate-100 p-3 text-slate-700 hover:bg-slate-200"
        >
          <MenuIcon />
        </button>
      </header>
      {drawerOpen && <div aria-hidden="true" className="fixed inset-0 z-20 bg-slate-900/40 md:hidden" onClick={closeDrawer} />}
      <aside
        id="barre-laterale"
        className={`fixed inset-y-0 left-0 z-30 flex w-72 flex-col border-r border-slate-200 bg-white p-5 transition-transform md:sticky md:top-0 md:h-screen md:shrink-0 md:translate-x-0 md:visible ${
          drawerOpen ? 'translate-x-0' : 'max-md:invisible -translate-x-full'
        }`}
      >
        <div className="flex items-start justify-between gap-3">
          <Brand user={user} onClick={closeDrawer} />
          <button type="button" aria-label="Fermer le menu" onClick={closeDrawer} className="rounded-lg p-2 text-slate-600 hover:bg-slate-100 md:hidden">
            <CloseIcon />
          </button>
        </div>
        <nav aria-label="Navigation principale" className="mt-8 flex-1 overflow-y-auto">
          <ul className="flex flex-col gap-1">
            {items.map((item) => (
              <li key={item.to}>
                <NavLink
                  to={item.to}
                  end={item.end}
                  onClick={closeDrawer}
                  className={({ isActive }) =>
                    `block rounded-xl px-4 py-3 font-medium ${isActive ? 'bg-indigo-50 text-indigo-800' : 'text-slate-700 hover:bg-slate-100'}`
                  }
                >
                  {item.label}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>
        <div className="mt-4 border-t border-slate-200 pt-4">
          <p className="font-semibold">{displayName(user)}</p>
          <p className="text-sm text-slate-600">{user.role_label}</p>
          <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm">
            <Link to="/profil" onClick={closeDrawer} className="text-indigo-700 underline hover:text-indigo-900">
              Mon profil
            </Link>
            <button type="button" onClick={handleLogout} disabled={logout.isPending} className="text-indigo-700 underline hover:text-indigo-900 disabled:opacity-60">
              {logoutLabel}
            </button>
          </div>
        </div>
      </aside>
      <main className="min-w-0 flex-1 px-4 py-6 md:px-8">
        <div className="mx-auto max-w-5xl">
          {messages}
          <Outlet />
        </div>
      </main>
    </div>
  )
}

/** Onglet en bas d'écran : icône au-dessus du libellé (apprenant-01, entreprise-01). */
function TabLink({ item }: { item: NavItem }) {
  const Icon = item.icon
  return (
    <NavLink
      to={item.to}
      end={item.end}
      className={({ isActive }) =>
        `flex flex-col items-center gap-1 px-2 py-3 text-sm font-medium ${isActive ? 'text-indigo-700' : 'text-slate-600 hover:text-slate-900'}`
      }
    >
      {Icon && <Icon className="h-6 w-6" />}
      {item.label}
    </NavLink>
  )
}

/** Lien entreprise : onglet sur mobile, ligne avec icône dans la barre latérale sur ordinateur. */
function EntrepriseLink({ item }: { item: NavItem }) {
  const Icon = item.icon
  return (
    <NavLink
      to={item.to}
      end={item.end}
      className={({ isActive }) =>
        `flex flex-col items-center gap-1 px-2 py-3 text-sm font-medium md:flex-row md:gap-3 md:rounded-xl md:px-4 md:text-base ${
          isActive ? 'text-indigo-700 md:bg-indigo-50' : 'text-slate-600 hover:text-slate-900 md:hover:bg-slate-100'
        }`
      }
    >
      {Icon && <Icon className="h-6 w-6 md:h-5 md:w-5" />}
      {item.label}
    </NavLink>
  )
}
