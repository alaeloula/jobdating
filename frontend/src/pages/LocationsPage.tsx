import { useState } from 'react'
import LocationEditor from '../events/LocationEditor'
import { useCurrentSummary, useParticipants, type ParticipantFilter } from '../events/api'

const button = 'min-h-9 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold hover:bg-slate-50'

/** Emplacements de l'événement courant (ADM-07, maquette cme-02) : administrateur et CME. */
export default function LocationsPage() {
  const summary = useCurrentSummary()
  const eventId = summary.data?.id ?? null
  const [filter, setFilter] = useState<Extract<ParticipantFilter, '' | 'sans_emplacement'>>('sans_emplacement')
  const participants = useParticipants(eventId, filter)
  const [notice, setNotice] = useState<string | null>(null)
  const rows = participants.data?.data ?? []
  const meta = participants.data?.meta

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold">Emplacements des entreprises</h1>
          <p className="text-slate-600">
            {summary.data ? `${summary.data.name} · ` : ''}Les entreprises sont ajoutées par l’administration ; l’équipe CME renseigne leur étage et leur table.
          </p>
        </div>
        {eventId !== null && (
          <div className="flex gap-1 rounded-xl bg-slate-200 p-1">
            {(
              [
                ['sans_emplacement', `Sans emplacement (${meta?.without_location ?? '…'})`],
                ['', `Toutes (${meta?.total ?? '…'})`],
              ] as const
            ).map(([value, label]) => (
              <button
                key={value}
                type="button"
                aria-pressed={filter === value}
                onClick={() => setFilter(value)}
                className={`rounded-lg px-3 py-2 text-sm font-semibold ${filter === value ? 'bg-white' : 'text-slate-700'}`}
              >
                {label}
              </button>
            ))}
          </div>
        )}
      </div>

      {notice && (
        <p role="status" className="rounded-lg border border-green-300 bg-green-50 px-4 py-2 text-green-800">
          {notice}
        </p>
      )}
      {summary.isPending && <p className="text-slate-600">Chargement…</p>}
      {summary.isError && (
        <div>
          <p role="alert" className="text-red-800">
            L’événement courant n’a pas pu être chargé.
          </p>
          <button type="button" className={`${button} mt-2`} onClick={() => void summary.refetch()}>
            Réessayer
          </button>
        </div>
      )}
      {summary.isSuccess && eventId === null && <p className="rounded-2xl border border-slate-200 bg-white p-5 text-slate-700">Aucun événement courant : l’administrateur doit en désigner un.</p>}

      {eventId !== null && (
        <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
          <table className="w-full text-left text-sm">
            <thead className="bg-slate-50 text-slate-600">
              <tr>
                <th className="px-4 py-3">Entreprise</th>
                <th className="px-4 py-3">Secteur</th>
                <th className="px-4 py-3">Étage et table</th>
              </tr>
            </thead>
            <tbody>
              {participants.isPending && (
                <tr>
                  <td colSpan={3} className="px-4 py-6 text-slate-600">
                    Chargement…
                  </td>
                </tr>
              )}
              {participants.isError && (
                <tr>
                  <td colSpan={3} className="px-4 py-6">
                    <p role="alert" className="text-red-800">
                      Les entreprises n’ont pas pu être chargées.
                    </p>
                    <button type="button" className={`${button} mt-2`} onClick={() => void participants.refetch()}>
                      Réessayer
                    </button>
                  </td>
                </tr>
              )}
              {participants.isSuccess && rows.length === 0 && (
                <tr>
                  <td colSpan={3} className="px-4 py-6 text-slate-600">
                    {filter === 'sans_emplacement' ? 'Toutes les entreprises ont un emplacement.' : 'Aucune entreprise.'}
                  </td>
                </tr>
              )}
              {!participants.isError &&
                rows.map((c) => {
                  const missing = c.floor === null && c.table_label === null
                  return (
                    <tr key={c.id} className={`border-t border-slate-100 ${missing ? 'bg-amber-50' : ''}`}>
                      <td className="px-4 py-3">
                        <p className="font-semibold">{c.name}</p>
                        {missing && <p className="text-orange-800">Emplacement à renseigner</p>}
                        {c.status === 'desactive' && <p className="text-xs font-semibold text-slate-500">Désactivée</p>}
                      </td>
                      <td className="px-4 py-3">{c.sector ?? '—'}</td>
                      <td className="px-4 py-3">
                        <LocationEditor key={`${eventId}-${c.id}-${c.floor}-${c.table_label}`} eventId={eventId} companyId={c.id} companyName={c.name} floor={c.floor} table={c.table_label} onSaved={setNotice} />
                      </td>
                    </tr>
                  )
                })}
            </tbody>
          </table>
        </div>
      )}
      <p className="text-sm text-slate-600">L’emplacement apparaît sur la fiche de connexion de l’entreprise et dans « Ma journée » des apprenants.</p>
    </div>
  )
}
