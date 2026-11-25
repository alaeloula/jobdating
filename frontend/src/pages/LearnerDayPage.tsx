import { STATUS_LABEL, formatDate, useMyLearnerEvent } from '../events/api'

function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => word[0]?.toUpperCase())
    .join('')
}

/**
 * « Ma journée » (APP-00, maquette apprenant-01) : événement courant et entreprises présentes. CV et
 * programme arrivent avec T-20 et T-15. Aucune donnée d'évaluation, aucun contact d'entreprise.
 */
export default function LearnerDayPage() {
  const day = useMyLearnerEvent()

  return (
    <section className="space-y-4">
      <h1 className="text-2xl font-bold">Ma journée</h1>
      {day.isPending && <p className="text-slate-600">Chargement…</p>}
      {day.isError && (
        <div>
          <p role="alert" className="text-red-800">
            Votre journée n’a pas pu être chargée.
          </p>
          <button type="button" onClick={() => void day.refetch()} className="mt-2 min-h-9 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold hover:bg-slate-50">
            Réessayer
          </button>
        </div>
      )}
      {day.isSuccess && day.data === null && <p className="rounded-2xl border border-slate-200 bg-white p-5 text-slate-700">Aucun événement ne vous concerne pour le moment.</p>}
      {day.data && (
        <>
          <div className="rounded-2xl border border-slate-200 bg-white p-5">
            <span className="rounded-full bg-emerald-50 px-3 py-1 text-sm font-semibold text-emerald-800">
              {day.data.event.status === 'en_cours' ? 'Aujourd’hui' : STATUS_LABEL[day.data.event.status]}
            </span>
            <p className="mt-2 text-xl font-bold">{day.data.event.name}</p>
            <p className="text-slate-600">
              {formatDate(day.data.event.date)} · {day.data.event.location}
            </p>
            {day.data.event.description && <p className="mt-2 text-sm text-slate-600">{day.data.event.description}</p>}
          </div>

          <h2 className="text-lg font-bold">Entreprises présentes ({day.data.companies.length})</h2>
          {day.data.companies.length === 0 && <p className="text-slate-600">Les entreprises seront bientôt annoncées.</p>}
          <ul className="space-y-2">
            {day.data.companies.map((c) => (
              <li key={c.id} className="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4">
                <span aria-hidden="true" className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-indigo-100 font-bold text-indigo-800">
                  {initials(c.name)}
                </span>
                <div className="min-w-0 flex-1">
                  <p className="font-semibold">{c.name}</p>
                  <p className="text-sm text-slate-600">{c.sector ?? 'Secteur non renseigné'}</p>
                </div>
                {(c.floor || c.table_label) && (
                  <span className="shrink-0 rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold">
                    {[c.floor ? `Étage ${c.floor}` : null, c.table_label].filter(Boolean).join(' · ')}
                  </span>
                )}
              </li>
            ))}
          </ul>
        </>
      )}
    </section>
  )
}
