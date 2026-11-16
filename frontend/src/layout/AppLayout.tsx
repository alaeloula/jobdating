import { Outlet, useLocation } from 'react-router'
import { useLogout, useMe } from '../auth/queries'
import type { FlashLocationState } from '../auth/types'

export const LOGOUT_FAILED_MESSAGE =
  "La déconnexion a échoué : votre session est peut-être encore ouverte. Vérifiez votre connexion et réessayez."

/** Mise en page commune des espaces connectés : identité, rôle, déconnexion. */
export default function AppLayout() {
  const { data: user } = useMe()
  const logout = useLogout()
  const location = useLocation()
  const flash = (location.state as FlashLocationState | null)?.flash

  // Après une déconnexion confirmée, l'utilisateur devient null et la garde de route
  // ramène à l'écran de connexion. En cas d'échec, on reste ici avec un message.
  const handleLogout = () => logout.mutate()

  return (
    <div className="min-h-screen bg-slate-100 text-slate-900">
      <header className="bg-slate-900 text-white">
        <div className="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-3">
          <span className="text-lg font-bold">JobDating</span>
          <div className="flex items-center gap-3 text-sm">
            {user && (
              <span className="hidden text-slate-300 sm:inline">
                {user.first_name} {user.last_name} · {user.role_label}
              </span>
            )}
            <button
              type="button"
              onClick={handleLogout}
              disabled={logout.isPending}
              className="rounded-lg bg-white/10 px-3 py-2 font-medium hover:bg-white/20 disabled:opacity-60"
            >
              {logout.isPending ? 'Déconnexion…' : 'Se déconnecter'}
            </button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-5xl px-4 py-6">
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
        <Outlet />
      </main>
    </div>
  )
}
