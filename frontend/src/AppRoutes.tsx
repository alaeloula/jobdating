import { Navigate, Route, Routes } from 'react-router'
import { ApiEventsListener, GuestOnly, RequireAuth, RootRedirect } from './auth/guards'
import AppLayout from './layout/AppLayout'
import AccountsPage from './pages/AccountsPage'
import ActivationPage from './pages/ActivationPage'
import AuditLogPage from './pages/AuditLogPage'
import CompaniesPage from './pages/CompaniesPage'
import CompanyImportPage from './pages/CompanyImportPage'
import CompanyHomePage from './pages/CompanyHomePage'
import EventsPage from './pages/EventsPage'
import ForgotPasswordPage from './pages/ForgotPasswordPage'
import HomePage from './pages/HomePage'
import LearnerDayPage from './pages/LearnerDayPage'
import LearnerImportPage from './pages/LearnerImportPage'
import CvPage from './pages/CvPage'
import LearnersPage from './pages/LearnersPage'
import LocationsPage from './pages/LocationsPage'
import LoginPage from './pages/LoginPage'
import ProfilePage from './pages/ProfilePage'
import ReferentialsPage from './pages/ReferentialsPage'
import ResetPasswordPage from './pages/ResetPasswordPage'

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

        {/* Liens reçus par e-mail (T-05) : accessibles avec ou sans session (décision B). */}
        <Route path="/activation" element={<ActivationPage />} />
        <Route path="/reinitialiser" element={<ResetPasswordPage />} />
        <Route
          path="/mot-de-passe-oublie"
          element={
            <GuestOnly>
              <ForgotPasswordPage />
            </GuestOnly>
          }
        />

        <Route element={<RequireAuth />}>
          <Route element={<AppLayout />}>
            <Route path="/profil" element={<ProfilePage />} />
            <Route element={<RequireAuth roles={['administrateur', 'cme', 'direction']} />}>
              <Route path="/pilotage" element={<HomePage title="Tableau de bord de pilotage" />} />
              <Route path="/apprenants" element={<LearnersPage />} />
            </Route>
            <Route element={<RequireAuth roles={['administrateur']} />}>
              <Route path="/journal-audit" element={<AuditLogPage />} />
              <Route path="/apprenants/import" element={<LearnerImportPage />} />
              <Route path="/comptes" element={<AccountsPage />} />
              <Route path="/evenements" element={<EventsPage />} />
              <Route path="/entreprises/import" element={<CompanyImportPage />} />
            </Route>
            <Route element={<RequireAuth roles={['administrateur', 'cme']} />}>
              <Route path="/referentiels" element={<ReferentialsPage />} />
              <Route path="/entreprises" element={<CompaniesPage />} />
              <Route path="/emplacements" element={<LocationsPage />} />
              <Route path="/cv" element={<CvPage />} />
            </Route>
            <Route element={<RequireAuth roles={['entreprise']} />}>
              <Route path="/entreprise" element={<CompanyHomePage />} />
            </Route>
            <Route element={<RequireAuth roles={['apprenant']} />}>
              <Route path="/ma-journee" element={<LearnerDayPage />} />
            </Route>
          </Route>
        </Route>

        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </>
  )
}
