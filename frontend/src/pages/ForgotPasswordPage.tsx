import { Link } from 'react-router'
import { EmailLinkForm, PublicShell } from '../passwords/components'

/** Mot de passe oublié (AUTH-03) : apprenants, CME, Direction, administrateur. */
export default function ForgotPasswordPage() {
  return (
    <PublicShell title="Mot de passe oublié">
      <p className="mt-3 text-slate-700">
        Saisissez votre adresse e-mail : vous recevrez un lien pour choisir un nouveau mot de passe, valable 60 minutes.
      </p>
      <EmailLinkForm path="/password/forgot" submitLabel="Recevoir le lien" />
      <p className="mt-6 text-sm text-slate-600">
        Entreprise : en cas d'oubli, adressez-vous à l'équipe d'organisation, qui réinitialisera votre mot de passe.
      </p>
      <p className="mt-4 text-center text-sm">
        <Link to="/login" className="font-semibold text-indigo-700 hover:underline">
          Retour à la connexion
        </Link>
      </p>
    </PublicShell>
  )
}
