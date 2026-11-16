import { Navigate, Route, Routes } from 'react-router'
import { ApiEventsListener, GuestOnly, RequireAuth, RootRedirect } from './auth/guards'
import AppLayout from './layout/AppLayout'
import AuditLogPage from './pages/AuditLogPage'
import HomePage from './pages/HomePage'
import LoginPage from './pages/LoginPage'
import ReferentialsPage from './pages/ReferentialsPage'

/**
 * Routes de l'application. Chaque rôle a sa page d'accueil (AUTH-01) ;
 * la garde par rôle ne fait que guider l'interface, le serveur vérifie les droits.
 */
export default function AppRoutes() {
  return (
    <>
      <ApiEventsListener />
      <Routes>
        <Route path="/" element={<RootRedirect />} />
        <Route
          path="/login"
          element={
            <GuestOnly>
              <LoginPage />
            </GuestOnly>
          }
        />

        <Route element={<RequireAuth />}>
          <Route element={<AppLayout />}>
            <Route element={<RequireAuth roles={['administrateur', 'cme', 'direction']} />}>
              <Route path="/pilotage" element={<HomePage title="Tableau de bord de pilotage" />} />
            </Route>
            <Route element={<RequireAuth roles={['administrateur']} />}>
              <Route path="/journal-audit" element={<AuditLogPage />} />
            </Route>
            <Route element={<RequireAuth roles={['administrateur', 'cme']} />}>
              <Route path="/referentiels" element={<ReferentialsPage />} />
            </Route>
            <Route element={<RequireAuth roles={['entreprise']} />}>
              <Route path="/entreprise" element={<HomePage title="Tableau de bord entreprise" />} />
            </Route>
            <Route element={<RequireAuth roles={['apprenant']} />}>
              <Route path="/ma-journee" element={<HomePage title="Ma journée" />} />
            </Route>
          </Route>
        </Route>

        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </>
  )
}
