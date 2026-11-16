import { Fragment, useState } from 'react'
import { useAuditFilterOptions, useAuditLogs, type AuditFilters, type AuditLogEntry } from '../audit/api'
import { ApiError } from '../lib/api'

const dateFormat = new Intl.DateTimeFormat('fr-FR', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
  timeZone: 'Africa/Casablanca',
})

function formatValue(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

/** Résumé d'une ligne : élément concerné et changements principaux. */
function summary(entry: AuditLogEntry): string {
  const parts: string[] = []
  if (entry.subject?.label) parts.push(entry.subject.label)
  const keys = Object.keys({ ...(entry.old_values ?? {}), ...(entry.new_values ?? {}) })
  for (const key of keys.slice(0, 2)) {
    const before = entry.old_values?.[key]
    const after = entry.new_values?.[key]
    parts.push(entry.old_values && key in entry.old_values ? `${key} : ${formatValue(before)} → ${formatValue(after)}` : `${key} : ${formatValue(after)}`)
  }
  return parts.join(' · ') || '—'
}

function Detail({ entry }: { entry: AuditLogEntry }) {
  const keys = Object.keys({ ...(entry.old_values ?? {}), ...(entry.new_values ?? {}) })
  return (
    <div className="space-y-2 text-sm">
      <p className="text-slate-600">
        {entry.subject ? `Élément : ${entry.subject.label ?? entry.subject.type} (${entry.subject.type} #${entry.subject.id ?? '—'})` : 'Aucun élément lié'}
        {entry.ip ? ` · Adresse IP : ${entry.ip}` : ''}
      </p>
      {keys.length > 0 ? (
        <table className="w-full text-left text-sm">
          <thead className="text-slate-500">
            <tr>
              <th className="py-1 pr-4 font-semibold">Champ</th>
              <th className="py-1 pr-4 font-semibold">Avant</th>
              <th className="py-1 font-semibold">Après</th>
            </tr>
          </thead>
          <tbody>
            {keys.map((key) => (
              <tr key={key} className="border-t border-slate-200">
                <td className="py-1 pr-4 font-medium">{key}</td>
                <td className="py-1 pr-4 break-all">{formatValue(entry.old_values?.[key])}</td>
                <td className="py-1 break-all">{formatValue(entry.new_values?.[key])}</td>
              </tr>
            ))}
          </tbody>
        </table>
      ) : (
        <p className="text-slate-600">Aucune valeur enregistrée.</p>
      )}
    </div>
  )
}

/** Journal d'audit (TRA-01), réservé à l'administrateur : d'après la maquette admin-13. */
export default function AuditLogPage() {
  const [filters, setFilters] = useState<AuditFilters>({ page: 1 })
  const [openId, setOpenId] = useState<number | null>(null)
  const options = useAuditFilterOptions()
  const logs = useAuditLogs(filters)

  const update = (patch: Partial<AuditFilters>) => {
    setOpenId(null)
    setFilters((current) => ({ ...current, ...patch, page: patch.page ?? 1 }))
  }

  const chip = (active: boolean) =>
    `min-h-10 rounded-full border px-4 text-sm font-semibold ${
      active ? 'border-indigo-700 bg-indigo-50 text-indigo-800' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
    }`

  const error = logs.error instanceof ApiError ? logs.error.message : logs.error ? 'Le journal ne peut pas être chargé.' : null
  const meta = logs.data?.meta

  return (
    <section className="space-y-4">
      <div>
        <h1 className="text-2xl font-bold">Journal d’audit</h1>
        <p className="mt-1 text-sm text-slate-600">
          Non modifiable. Conservé 1 an, puis purgé automatiquement. Consultation réservée à l’administration.
        </p>
      </div>

      <div role="group" aria-label="Catégories" className="flex flex-wrap gap-2">
        <button type="button" aria-pressed={!filters.category} className={chip(!filters.category)} onClick={() => update({ category: undefined })}>
          Toutes les actions
        </button>
        {options.data?.categories.map((c) => (
          <button
            key={c.value}
            type="button"
            aria-pressed={filters.category === c.value}
            className={chip(filters.category === c.value)}
            onClick={() => update({ category: c.value })}
          >
            {c.label}
          </button>
        ))}
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <label className="flex flex-col gap-1 text-sm font-semibold text-slate-700">
          Auteur
          <select
            value={filters.user_id ?? ''}
            onChange={(e) => update({ user_id: e.target.value || undefined })}
            className="h-10 min-w-56 rounded-lg border border-slate-300 bg-white px-3 font-normal"
          >
            <option value="">Tous les auteurs</option>
            {options.data?.authors.map((a) => (
              <option key={a.id} value={String(a.id)}>
                {a.label}
              </option>
            ))}
          </select>
        </label>
        <label className="flex flex-col gap-1 text-sm font-semibold text-slate-700">
          Du
          <input
            type="date"
            value={filters.from ?? ''}
            onChange={(e) => update({ from: e.target.value || undefined })}
            className="h-10 rounded-lg border border-slate-300 bg-white px-3 font-normal"
          />
        </label>
        <label className="flex flex-col gap-1 text-sm font-semibold text-slate-700">
          Au
          <input
            type="date"
            value={filters.to ?? ''}
            onChange={(e) => update({ to: e.target.value || undefined })}
            className="h-10 rounded-lg border border-slate-300 bg-white px-3 font-normal"
          />
        </label>
      </div>

      {error && (
        <p role="alert" className="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-red-800">
          {error}
        </p>
      )}

      <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
        <table className="w-full min-w-[720px] text-left text-sm">
          <thead className="bg-slate-50 text-slate-600">
            <tr>
              <th scope="col" className="px-4 py-3 font-semibold">Date</th>
              <th scope="col" className="px-4 py-3 font-semibold">Auteur</th>
              <th scope="col" className="px-4 py-3 font-semibold">Action</th>
              <th scope="col" className="px-4 py-3 font-semibold">Détail</th>
            </tr>
          </thead>
          <tbody>
            {logs.isPending && (
              <tr>
                <td colSpan={4} className="px-4 py-6 text-center text-slate-500">Chargement…</td>
              </tr>
            )}
            {logs.data?.data.length === 0 && (
              <tr>
                <td colSpan={4} className="px-4 py-6 text-center text-slate-500">Aucune action pour ces filtres.</td>
              </tr>
            )}
            {logs.data?.data.map((entry) => {
              const open = openId === entry.id
              return (
                <Fragment key={entry.id}>
                  <tr className="border-t border-slate-100">
                    <td className="px-4 py-3 whitespace-nowrap text-slate-600">{dateFormat.format(new Date(entry.created_at))}</td>
                    <td className="px-4 py-3 font-semibold">{entry.actor?.label ?? 'Visiteur non connecté'}</td>
                    <td className="px-4 py-3">{entry.action_label}</td>
                    <td className="px-4 py-3 text-slate-700">
                      <button
                        type="button"
                        aria-expanded={open}
                        onClick={() => setOpenId(open ? null : entry.id)}
                        className="text-left hover:underline"
                      >
                        {summary(entry)}
                      </button>
                    </td>
                  </tr>
                  {open && (
                    <tr className="bg-slate-50">
                      <td colSpan={4} className="px-4 py-3">
                        <Detail entry={entry} />
                      </td>
                    </tr>
                  )}
                </Fragment>
              )
            })}
          </tbody>
        </table>
      </div>

      {meta && meta.last_page > 1 && (
        <nav aria-label="Pagination" className="flex items-center justify-between text-sm">
          <button
            type="button"
            disabled={meta.current_page <= 1}
            onClick={() => update({ page: meta.current_page - 1 })}
            className="min-h-10 rounded-lg border border-slate-300 bg-white px-4 font-semibold disabled:opacity-50"
          >
            Précédent
          </button>
          <span className="text-slate-600">
            Page {meta.current_page} / {meta.last_page} · {meta.total} actions
          </span>
          <button
            type="button"
            disabled={meta.current_page >= meta.last_page}
            onClick={() => update({ page: meta.current_page + 1 })}
            className="min-h-10 rounded-lg border border-slate-300 bg-white px-4 font-semibold disabled:opacity-50"
          >
            Suivant
          </button>
        </nav>
      )}
    </section>
  )
}
