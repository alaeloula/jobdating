import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router'
import LocationEditor from './LocationEditor'
import {
  addCompanies,
  errorMessage,
  placementLabel,
  removeCompany,
  useAvailableCompanies,
  useEvents,
  useParticipants,
  useRefreshEvents,
  type Participant,
  type ParticipantFilter,
} from './api'

const button = 'min-h-9 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold hover:bg-slate-50 disabled:opacity-50'

/**
 * Entreprises participantes d'un événement (ADM-07, maquette admin-03, sans l'import par fichier).
 * Le retrait n'est proposé qu'à une entreprise sans passage (RG-PAR-04).
 */
export default function ParticipantsPanel({ onReset }: { onReset: (company: Pick<Participant, 'id' | 'name'>, eventId: number) => void }) {
  const events = useEvents()
  const refresh = useRefreshEvents()
  const [chosenId, setChosenId] = useState<number | null>(null)
  const [filter, setFilter] = useState<ParticipantFilter>('')
  const [adding, setAdding] = useState(false)
  const [picked, setPicked] = useState<number[]>([])
  const [editing, setEditing] = useState<number | null>(null)
  const [confirming, setConfirming] = useState<Participant | null>(null)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  // Une réponse d'action arrivée après un changement d'événement n'affiche plus rien.
  const generation = useRef(0)

  const list = events.data ?? []
  const event = list.find((e) => e.id === chosenId) ?? list.find((e) => e.is_current) ?? list.find((e) => !e.archived) ?? list[0] ?? null
  const eventId = event?.id ?? null

  // Tout changement d'événement, choisi ou venu d'une actualisation, repart d'un état vierge.
  const [shownEventId, setShownEventId] = useState(eventId)
  if (shownEventId !== eventId) {
    setShownEventId(eventId)
    setFilter('')
    setAdding(false)
    setPicked([])
    setEditing(null)
    setConfirming(null)
    setBusy(false)
    setNotice(null)
    setError(null)
  }
  useEffect(() => {
    generation.current += 1
  }, [eventId])

  const participants = useParticipants(eventId, filter)
  const available = useAvailableCompanies(eventId, adding)
  const readOnly = event?.archived ?? false
  const meta = participants.data?.meta
  const rows = participants.data?.data ?? []

  const run = async (work: () => Promise<string>, onSuccess?: () => void) => {
    if (busy) return
    const current = generation.current
    setBusy(true)
    setError(null)
    setNotice(null)
    try {
      const message = await work()
      await refresh()
      if (current === generation.current) {
        onSuccess?.()
        setNotice(message)
      }
    } catch (e) {
      if (current === generation.current) setError(errorMessage(e))
    } finally {
      if (current === generation.current) setBusy(false)
    }
  }

  const add = () =>
    void run(
      async () => {
        const report = await addCompanies(eventId as number, picked)
        return `${report.added} entreprise(s) ajoutée(s), ${report.already} déjà inscrite(s)${report.ignored > 0 ? `, ${report.ignored} ignorée(s) (compte désactivé)` : ''}.`
      },
      // Seulement si l'événement affiché est toujours celui de la demande (revue T-14, constat 5).
      () => {
        setPicked([])
        setAdding(false)
      },
    )

  const remove = (company: Participant) =>
    void run(async () => {
      setConfirming(null)
      await removeCompany(eventId as number, company.id)
      return `${company.name} est retirée de l’événement.`
    })

  if (events.isPending) return <p className="text-slate-600">Chargement…</p>
  if (events.isError) {
    return (
      <div>
        <p role="alert" className="text-red-800">
          Les événements n’ont pas pu être chargés.
        </p>
        <button type="button" className={`${button} mt-2`} onClick={() => void events.refetch()}>
          Réessayer
        </button>
      </div>
    )
  }
  if (!event) {
    return (
      <p className="rounded-2xl border border-slate-200 bg-white p-5 text-slate-700">
        Aucun événement n’existe encore.{' '}
        <Link to="/evenements" className="font-semibold text-indigo-700 underline">
          Créer un événement
        </Link>
      </p>
    )
  }

  const chips: [ParticipantFilter, string][] = [
    ['', `Toutes (${meta?.total ?? '…'})`],
    ['sans_emplacement', `Sans emplacement (${meta?.without_location ?? '…'})`],
    ['non_imprimee', `Fiche non imprimée (${meta?.not_printed ?? '…'})`],
  ]

  return (
    <section className="space-y-3" aria-label="Participantes à l’événement">
      <div className="flex flex-wrap items-center gap-2">
        <label className="text-sm font-semibold">
          Événement{' '}
          <select aria-label="Événement" value={event.id} onChange={(e) => setChosenId(Number(e.target.value))} className="min-h-9 rounded-lg border border-slate-300 bg-white px-3 font-normal">
            {list.map((e) => (
              <option key={e.id} value={e.id}>
                {e.full_name}
                {e.is_current ? ' (courant)' : ''}
                {e.archived ? ' (archivé)' : ''}
              </option>
            ))}
          </select>
        </label>
        {chips.map(([value, label]) => (
          <button
            key={value}
            type="button"
            aria-pressed={filter === value}
            onClick={() => setFilter(value)}
            className={`rounded-full border px-3 py-1 text-sm ${filter === value ? 'border-indigo-600 bg-indigo-50 font-semibold text-indigo-800' : 'border-slate-300'}`}
          >
            {label}
          </button>
        ))}
        {!readOnly && (
          <button type="button" className={button} aria-expanded={adding} onClick={() => setAdding(!adding)}>
            Ajouter des entreprises existantes
          </button>
        )}
      </div>

      {readOnly && <p className="rounded-lg border border-slate-300 bg-slate-50 px-4 py-2 text-slate-700">Événement archivé : lecture seule.</p>}
      {notice && (
        <p role="status" className="rounded-lg border border-green-300 bg-green-50 px-4 py-2 text-green-800">
          {notice}
        </p>
      )}
      {error && (
        <p role="alert" className="rounded-lg border border-red-300 bg-red-50 px-4 py-2 text-red-800">
          {error}
        </p>
      )}

      {adding && (
        <div className="rounded-2xl border border-slate-200 bg-white p-4">
          <h2 className="font-bold">Ajouter des entreprises à {event.full_name}</h2>
          {available.isPending && <p className="mt-2 text-slate-600">Chargement…</p>}
          {available.isError && (
            <div className="mt-2">
              <p role="alert" className="text-red-800">
                La liste n’a pas pu être chargée.
              </p>
              <button type="button" className={`${button} mt-2`} onClick={() => void available.refetch()}>
                Réessayer
              </button>
            </div>
          )}
          {available.data?.length === 0 && <p className="mt-2 text-slate-600">Toutes les entreprises actives sont déjà inscrites.</p>}
          {(available.data?.length ?? 0) > 0 && (
            <>
              <ul className="mt-2 grid max-h-64 gap-1 overflow-y-auto sm:grid-cols-2">
                {available.data?.map((c) => (
                  <li key={c.id}>
                    <label className="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        checked={picked.includes(c.id)}
                        onChange={() => setPicked((p) => (p.includes(c.id) ? p.filter((x) => x !== c.id) : [...p, c.id]))}
                      />
                      {c.name}
                      {c.sector ? <span className="text-slate-500">· {c.sector}</span> : null}
                    </label>
                  </li>
                ))}
              </ul>
              <button type="button" disabled={busy || picked.length === 0} onClick={add} className="mt-3 min-h-9 rounded-lg bg-indigo-600 px-3 text-sm font-bold text-white hover:bg-indigo-700 disabled:opacity-50">
                Ajouter {picked.length} entreprise(s)
              </button>
            </>
          )}
        </div>
      )}

      {confirming && (
        <div role="alertdialog" aria-label="Confirmer le retrait" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-900">
          <p>Retirer {confirming.name} de {event.full_name} ? Son compte est conservé.</p>
          <div className="mt-3 flex gap-2">
            <button type="button" disabled={busy} onClick={() => remove(confirming)} className="rounded-lg bg-amber-600 px-3 py-2 font-semibold text-white disabled:opacity-50">
              Confirmer le retrait
            </button>
            <button type="button" className={button} onClick={() => setConfirming(null)}>
              Annuler
            </button>
          </div>
        </div>
      )}

      <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="bg-slate-50 text-slate-600">
            <tr>
              <th className="px-4 py-3">Entreprise</th>
              <th className="px-4 py-3">Emplacement</th>
              <th className="px-4 py-3">Nom d’utilisateur</th>
              <th className="px-4 py-3">Actions</th>
            </tr>
          </thead>
          <tbody>
            {participants.isPending && (
              <tr>
                <td colSpan={4} className="px-4 py-6 text-slate-600">
                  Chargement…
                </td>
              </tr>
            )}
            {participants.isError && (
              <tr>
                <td colSpan={4} className="px-4 py-6">
                  <p role="alert" className="text-red-800">
                    Les participantes n’ont pas pu être chargées.
                  </p>
                  <button type="button" className={`${button} mt-2`} onClick={() => void participants.refetch()}>
                    Réessayer
                  </button>
                </td>
              </tr>
            )}
            {participants.isSuccess && rows.length === 0 && (
              <tr>
                <td colSpan={4} className="px-4 py-6 text-slate-600">
                  Aucune entreprise.
                </td>
              </tr>
            )}
            {!participants.isError &&
              rows.map((c) => (
                <tr key={c.id} className="border-t border-slate-100 align-top">
                  <td className="px-4 py-3">
                    <p className="font-semibold">{c.name}</p>
                    <p className="text-slate-500">{c.sector ?? 'Secteur non renseigné'}</p>
                    {c.status === 'desactive' && <p className="text-xs font-semibold text-slate-500">Désactivée</p>}
                  </td>
                  <td className="px-4 py-3">
                    {editing === c.id ? (
                      <LocationEditor
                        key={`${event.id}-${c.id}-${c.floor}-${c.table_label}`}
                        eventId={event.id}
                        companyId={c.id}
                        companyName={c.name}
                        floor={c.floor}
                        table={c.table_label}
                        onSaved={(message) => {
                          setEditing(null)
                          setError(null)
                          setNotice(message)
                        }}
                      />
                    ) : (
                      (placementLabel(c.floor, c.table_label) ?? <span className="text-orange-700">À renseigner</span>)
                    )}
                  </td>
                  <td className="px-4 py-3 font-mono">{c.username}</td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-2">
                      {!readOnly && (
                        <button type="button" className={button} onClick={() => setEditing(editing === c.id ? null : c.id)}>
                          {editing === c.id ? 'Fermer' : 'Emplacement'}
                        </button>
                      )}
                      {c.status === 'actif' && (
                        <button type="button" className={button} onClick={() => onReset(c, event.id)}>
                          Réinitialiser le mot de passe
                        </button>
                      )}
                      {!readOnly &&
                        (c.can_remove ? (
                          <button type="button" disabled={busy} className={`${button} text-red-700`} onClick={() => setConfirming(c)}>
                            Retirer
                          </button>
                        ) : (
                          <button type="button" disabled className={button}>
                            Retrait impossible
                          </button>
                        ))}
                    </div>
                  </td>
                </tr>
              ))}
          </tbody>
        </table>
      </div>
      <p className="text-sm text-slate-600">Une entreprise ne peut être retirée de l’événement que si elle n’a aucun passage (par exemple absente le jour J).</p>
    </section>
  )
}
