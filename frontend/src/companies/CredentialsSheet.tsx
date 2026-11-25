import { useState } from 'react'
import { formatDate, placementLabel } from '../events/api'
import { ApiError } from '../lib/api'
import { confirmPrinted, type Credentials } from './api'

const button = 'min-h-10 rounded-lg border border-slate-300 bg-white px-3 font-semibold hover:bg-slate-50 disabled:opacity-50'

/**
 * Fiche de connexion (maquette admin-05, RG-ENTR-05 / 07) : affichée une seule fois après la
 * création ou la réinitialisation. « J'ai imprimé la fiche » confirme l'impression de CETTE
 * version des identifiants (décision du 19/11) ; le mot de passe disparaît à la fermeture.
 */
export default function CredentialsSheet({
  companyId,
  companyName,
  credentials,
  onClose,
  onPrinted,
}: {
  companyId: number
  companyName: string
  credentials: Credentials
  onClose: () => void
  onPrinted: () => void
}) {
  const [printed, setPrinted] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)

  const confirm = async () => {
    setError(null)
    setPending(true)
    try {
      await confirmPrinted(companyId, credentials.version)
      setPrinted(true)
      onPrinted()
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Une erreur est survenue.')
    } finally {
      setPending(false)
    }
  }

  return (
    <section aria-label="Fiche de connexion" className="space-y-3">
      <div className="fiche-connexion rounded-2xl border-2 border-slate-900 bg-white p-5">
        <p className="text-lg font-bold text-indigo-800">JobDating YouCode</p>
        <p className="mt-2 text-2xl font-bold">{companyName}</p>
        {credentials.event && (
          <dl className="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm" data-testid="sheet-event">
            <dt className="text-slate-600">Événement</dt>
            <dd className="font-semibold">{credentials.event.name}</dd>
            <dt className="text-slate-600">Date</dt>
            <dd>{formatDate(credentials.event.date)}</dd>
            <dt className="text-slate-600">Lieu</dt>
            <dd>{credentials.event.location}</dd>
            <dt className="text-slate-600">Emplacement</dt>
            <dd>{placementLabel(credentials.event.floor, credentials.event.table_label) ?? 'À confirmer sur place'}</dd>
          </dl>
        )}
        <dl className="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 rounded-lg bg-slate-100 p-4">
          <dt className="text-slate-600">Adresse</dt>
          <dd className="font-mono break-all">{credentials.url}</dd>
          <dt className="text-slate-600">Nom d’utilisateur</dt>
          <dd className="font-mono font-bold" data-testid="sheet-username">
            {credentials.username}
          </dd>
          <dt className="text-slate-600">Mot de passe</dt>
          <dd className="font-mono font-bold" data-testid="sheet-password">
            {credentials.password}
          </dd>
        </dl>
        <p className="mt-3 text-sm text-slate-600">En cas d’oubli, adressez-vous à l’équipe d’organisation, qui réinitialisera votre mot de passe.</p>
      </div>

      {error && (
        <p role="alert" className="rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">
          {error}
        </p>
      )}
      {printed && (
        <p role="status" className="rounded-lg border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-800">
          Impression confirmée.
        </p>
      )}

      <div className="flex flex-wrap gap-2">
        <button type="button" onClick={() => window.print()} className="min-h-10 rounded-lg bg-indigo-600 px-4 font-bold text-white hover:bg-indigo-700">
          Imprimer la fiche
        </button>
        <button type="button" className={button} onClick={() => void navigator.clipboard?.writeText(credentials.password)}>
          Copier le mot de passe
        </button>
        <button type="button" className={button} disabled={printed || pending} onClick={() => void confirm()}>
          J’ai imprimé la fiche
        </button>
        <button type="button" className={button} onClick={onClose}>
          Fermer
        </button>
      </div>
      <p className="text-sm text-slate-600">
        Le mot de passe ne sera plus affiché après la fermeture. « Réinitialiser le mot de passe » en génère un nouveau ; l’ancien ne
        fonctionne plus.
      </p>
    </section>
  )
}
