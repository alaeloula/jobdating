import { useRef, useState, type FormEvent } from 'react'
import { ApiError } from '../lib/api'
import {
  STATUS_LABEL,
  archiveEvent,
  deleteEvent,
  errorMessage,
  formatDate,
  saveEvent,
  schoolYear,
  setCurrentEvent,
  unsetCurrentEvent,
  useEvents,
  useRefreshEvents,
  type EventItem,
  type EventReport,
} from '../events/api'

const input = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 disabled:bg-slate-100'
const button = 'min-h-9 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold hover:bg-slate-50 disabled:opacity-50'

type Errors = Record<string, string[]>

function reportText(report: EventReport): string {
  return `${report.enrolled} apprenant(s) inscrit(s), ${report.already} déjà inscrit(s), ${report.ignored_deactivated} désactivé(s) et ${report.ignored_removed} retiré(s) ignorés.`
}

function EventForm({ event, onSaved, onCancel }: { event: EventItem | null; onSaved: (message: string) => void; onCancel?: () => void }) {
  const refresh = useRefreshEvents()
  const [values, setValues] = useState({
    name: event?.name ?? 'JobDating',
    date: event?.date ?? '',
    location: event?.location ?? '',
    description: event?.description ?? '',
    latitude: event?.latitude ?? '',
    longitude: event?.longitude ?? '',
  })
  const [errors, setErrors] = useState<Errors>({})
  const [message, setMessage] = useState<string | null>(null)
  const [pending, setPending] = useState(false)
  const readOnly = event?.archived ?? false
  const year = schoolYear(values.date)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (pending) return
    setPending(true)
    setErrors({})
    setMessage(null)
    try {
      const saved = await saveEvent(event?.id ?? null, {
        name: values.name,
        date: values.date,
        location: values.location,
        description: values.description.trim() || null,
        latitude: values.latitude === '' ? null : values.latitude,
        longitude: values.longitude === '' ? null : values.longitude,
      })
      await refresh()
      onSaved(saved.reminder ? `${saved.data.full_name} créé. ${saved.reminder}` : `${saved.data.full_name} enregistré.`)
    } catch (err) {
      if (err instanceof ApiError && Object.keys(err.errors).length > 0) setErrors(err.errors)
      else setMessage(errorMessage(err))
    } finally {
      setPending(false)
    }
  }

  const field = (name: keyof typeof values, label: string, type = 'text', disabled = readOnly, help?: string) => (
    <div>
      <label htmlFor={`event-${name}`} className="block font-semibold text-slate-700">
        {label}
      </label>
      <input
        id={`event-${name}`}
        type={type}
        value={values[name]}
        disabled={disabled}
        aria-invalid={errors[name] ? true : undefined}
        onChange={(e) => setValues((v) => ({ ...v, [name]: e.target.value }))}
        className={input}
      />
      {help && <p className="mt-1 text-sm text-slate-600">{help}</p>}
      {errors[name] && <p className="mt-1 text-sm text-red-700">{errors[name][0]}</p>}
    </div>
  )

  return (
    <form onSubmit={(e) => void submit(e)} noValidate className="space-y-4">
      {message && (
        <p role="alert" className="rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">
          {message}
        </p>
      )}
      {field('name', 'Nom (sans l’année)', 'text', readOnly, 'L’année scolaire est ajoutée automatiquement.')}
      {field('date', 'Date (une journée)', 'date', readOnly || (event?.date_locked ?? false), event?.date_locked ? 'Cet événement a des passages : sa date n’est plus modifiable.' : undefined)}
      <p className="rounded-lg bg-slate-100 px-3 py-2 text-sm" data-testid="event-full-name">
        Nom affiché : <strong>{year ? `${values.name.trim()} ${year}` : 'choisissez une date'}</strong>
      </p>
      {field('location', 'Lieu')}
      <div>
        <label htmlFor="event-description" className="block font-semibold text-slate-700">
          Description
        </label>
        <textarea id="event-description" rows={3} value={values.description} disabled={readOnly} onChange={(e) => setValues((v) => ({ ...v, description: e.target.value }))} className={input} />
        {errors.description && <p className="mt-1 text-sm text-red-700">{errors.description[0]}</p>}
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        {field('latitude', 'Latitude (facultative)')}
        {field('longitude', 'Longitude (facultative)')}
      </div>
      {!readOnly && (
        <div className="flex justify-end gap-2">
          {onCancel && (
            <button type="button" className={button} onClick={onCancel}>
              Annuler
            </button>
          )}
          <button type="submit" disabled={pending} className="rounded-xl bg-indigo-600 px-4 py-2 font-bold text-white hover:bg-indigo-700 disabled:opacity-60">
            {pending ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </div>
      )}
    </form>
  )
}

/**
 * Événement (ADM-05, maquette admin-02, sans le programme : T-15). Administrateur uniquement ; le
 * serveur applique toutes les règles, cet écran ne fait que les refléter.
 */
export default function EventsPage() {
  const events = useEvents()
  const refresh = useRefreshEvents()
  const [selected, setSelected] = useState<number | 'new' | null>(null)
  const [confirmDelete, setConfirmDelete] = useState<EventItem | null>(null)
  const [busy, setBusy] = useState(false)
  const lock = useRef(false)
  const [notice, setNotice] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const list = events.data ?? []
  const current = list.find((e) => e.is_current) ?? null
  const shown = selected === 'new' ? null : (list.find((e) => e.id === selected) ?? current ?? list.find((e) => !e.archived) ?? list[0] ?? null)
  const creating = selected === 'new' || (events.isSuccess && list.length === 0)
  const others = list.filter((e) => e.id !== shown?.id || creating)

  const run = async (work: () => Promise<string>) => {
    if (lock.current) return
    lock.current = true
    setBusy(true)
    setError(null)
    setNotice(null)
    try {
      const message = await work()
      await refresh()
      setNotice(message)
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      lock.current = false
      setBusy(false)
    }
  }

  const actions = (event: EventItem) => (
    <div className="flex flex-wrap gap-2">
      {!event.archived && !event.is_current && event.status !== 'termine' && (
        <button type="button" disabled={busy} className={button} onClick={() => void run(async () => `${event.full_name} est l’événement courant. ${reportText((await setCurrentEvent(event.id)).report)}`)}>
          Désigner comme courant
        </button>
      )}
      {event.is_current && (
        <button type="button" disabled={busy} className={button} onClick={() => void run(async () => (await unsetCurrentEvent(), `${event.full_name} n’est plus l’événement courant.`))}>
          Retirer le statut courant
        </button>
      )}
      {!event.is_current && !event.archived && (
        <button type="button" disabled={busy} className={button} onClick={() => void run(async () => (await archiveEvent(event.id, true), `${event.full_name} est archivé.`))}>
          Archiver
        </button>
      )}
      {event.archived && (
        <button type="button" disabled={busy} className={button} onClick={() => void run(async () => (await archiveEvent(event.id, false), `${event.full_name} est désarchivé.`))}>
          Désarchiver
        </button>
      )}
      {!event.is_current && !event.archived && (
        <button type="button" disabled={busy} className={`${button} text-red-700`} onClick={() => setConfirmDelete(event)}>
          Supprimer
        </button>
      )}
    </div>
  )

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold">Événement et programme</h1>
          <p className="text-slate-600">Un JobDating par année scolaire. Seul l’administrateur crée et modifie l’événement.</p>
        </div>
        <button type="button" onClick={() => setSelected('new')} className="rounded-xl bg-indigo-600 px-4 py-2 font-bold text-white hover:bg-indigo-700">
          Nouvel événement
        </button>
      </div>

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
      {confirmDelete && (
        <div role="alertdialog" aria-label="Confirmer la suppression" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-900">
          <p>Supprimer définitivement {confirmDelete.full_name} et ses participations ? Cette action est irréversible.</p>
          <div className="mt-3 flex gap-2">
            <button
              type="button"
              disabled={busy}
              className="rounded-lg bg-red-700 px-3 py-2 font-semibold text-white disabled:opacity-50"
              onClick={() => {
                const target = confirmDelete
                setConfirmDelete(null)
                if (selected === target.id) setSelected(null)
                void run(async () => (await deleteEvent(target.id), `${target.full_name} est supprimé.`))
              }}
            >
              Confirmer la suppression
            </button>
            <button type="button" className={button} onClick={() => setConfirmDelete(null)}>
              Annuler
            </button>
          </div>
        </div>
      )}

      {events.isPending && <p className="text-slate-600">Chargement…</p>}
      {events.isError && (
        <div>
          <p role="alert" className="text-red-800">
            Les événements n’ont pas pu être chargés.
          </p>
          <button type="button" className={`${button} mt-2`} onClick={() => void events.refetch()}>
            Réessayer
          </button>
        </div>
      )}

      {events.isSuccess && (
        <section className="max-w-2xl rounded-2xl border border-slate-200 bg-white p-5">
          <h2 className="text-lg font-bold">{creating ? 'Nouvel événement' : 'Événement'}</h2>
          {!creating && shown && (
            <div className="mt-2 space-y-3">
              <p className="flex flex-wrap items-center gap-2 text-sm">
                {shown.is_current && <span className="rounded-full bg-emerald-50 px-3 py-1 font-semibold text-emerald-800">Événement courant</span>}
                {shown.archived && <span className="rounded-full bg-slate-100 px-3 py-1 font-semibold text-slate-700">Archivé</span>}
                <span className="text-slate-600">Statut calculé : {STATUS_LABEL[shown.status]}</span>
              </p>
              {actions(shown)}
            </div>
          )}
          <div className="mt-4">
            <EventForm
              key={creating ? 'new' : `${shown?.id}-${shown?.date}-${shown?.name}-${shown?.archived}`}
              event={creating ? null : shown}
              onCancel={selected === 'new' && list.length > 0 ? () => setSelected(null) : undefined}
              onSaved={(message) => {
                setError(null)
                setNotice(message)
                if (selected === 'new') setSelected(null)
              }}
            />
          </div>
          <p className="mt-3 text-sm text-slate-600">Quand un événement devient courant, tous les apprenants actifs y sont inscrits automatiquement.</p>
        </section>
      )}

      {others.length > 0 && (
        <section className="rounded-2xl border border-slate-200 bg-white p-5">
          <h2 className="text-lg font-bold">Éditions précédentes et à venir</h2>
          <ul className="mt-3 divide-y divide-slate-100">
            {others.map((e) => (
              <li key={e.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                <div>
                  <p className="font-semibold">{e.full_name}</p>
                  <p className="text-sm text-slate-600">
                    {formatDate(e.date)} · {STATUS_LABEL[e.status]}
                    {e.archived ? ' · Archivé' : ''}
                  </p>
                </div>
                <div className="flex flex-wrap gap-2">
                  <button type="button" className={button} onClick={() => setSelected(e.id)}>
                    Ouvrir
                  </button>
                  {actions(e)}
                </div>
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  )
}
