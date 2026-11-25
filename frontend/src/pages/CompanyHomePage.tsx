import { useMe } from '../auth/queries'
import { formatDate, placementLabel, useMyCompanyEvent } from '../events/api'

/**
 * Accueil de l'entreprise (ENT-01, maquette entreprise-ordi-01) : uniquement l'événement COURANT
 * (décisions 10 et 12 du 23/11). Compteurs, passages et programme arrivent avec leurs tâches.
 */
export default function CompanyHomePage() {
  const { data: me } = useMe()
  const event = useMyCompanyEvent()
  const place = event.data ? placementLabel(event.data.floor, event.data.table_label) : null

  return (
    <section>
      <h1 className="text-3xl font-bold">Bonjour, {`${me?.first_name ?? ''} ${me?.last_name ?? ''}`.trim()}</h1>
      {event.isPending && <p className="mt-2 text-slate-600">Chargement…</p>}
      {event.isError && (
        <div className="mt-3">
          <p role="alert" className="text-red-800">
            L’événement n’a pas pu être chargé.
          </p>
          <button type="button" onClick={() => void event.refetch()} className="mt-2 min-h-9 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold hover:bg-slate-50">
            Réessayer
          </button>
        </div>
      )}
      {event.data && (
        <p className="mt-2 text-slate-600">
          {event.data.name} · {formatDate(event.data.date)} · {event.data.location} · Votre emplacement : {place ?? 'à venir'}
        </p>
      )}
      {event.isSuccess && event.data === null && (
        <p className="mt-4 rounded-2xl border border-slate-200 bg-white p-5 text-slate-700">
          Votre entreprise ne participe pas à l’événement de cette année. Contactez l’équipe d’organisation si vous pensez qu’il s’agit d’une erreur.
        </p>
      )}
    </section>
  )
}

/** Encadré du menu (entreprise-ordi-01) ; rien tant que l'entreprise n'a pas d'événement courant. */
export function CompanyEventCard() {
  const { data } = useMyCompanyEvent()
  if (!data) return null

  return (
    <div className="mt-5 rounded-2xl bg-slate-100 p-4 text-sm" data-testid="company-event-card">
      <p className="font-bold">{data.name}</p>
      <p className="text-slate-600">
        {formatDate(data.date)}
        {data.table_label ? ` · Table ${data.table_label}` : ''}
      </p>
    </div>
  )
}
