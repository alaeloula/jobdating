import { useState } from 'react'
import { ApiError } from '../lib/api'
import type { Learner } from '../learners/api'
import { HISTORY_LABEL, formatDate, useCvHistory } from './api'

const button = 'min-h-9 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold hover:bg-slate-50 disabled:opacity-50'

function size(bytes: number | null): string {
  if (bytes === null) return ''
  return bytes >= 1024 * 1024 ? `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} Mo` : `${Math.max(1, Math.round(bytes / 1024))} Ko`
}

/** Historique des ajouts de CV d'un apprenant (CV-04), du plus récent au plus ancien. */
export default function CvHistoryPanel({ learner, onClose }: { learner: Learner; onClose: () => void }) {
  const [page, setPage] = useState(1)
  const history = useCvHistory(learner.id, page)
  const rows = history.data?.data ?? []
  const meta = history.data?.meta

  return (
    <aside aria-label={`Historique du CV de ${learner.first_name} ${learner.last_name}`} className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5">
      <div className="flex items-start justify-between gap-2">
        <h2 className="font-bold">
          Historique du CV · {learner.first_name} {learner.last_name}
        </h2>
        <button type="button" className={button} onClick={onClose}>
          Fermer
        </button>
      </div>
      {history.isLoading && <p className="text-slate-600">Chargement…</p>}
      {history.isError && (
        <div role="alert" className="text-red-800">
          <p>{history.error instanceof ApiError ? history.error.message : 'L’historique n’a pas pu être chargé.'}</p>
          <button type="button" className={`${button} mt-2`} onClick={() => void history.refetch()}>
            Réessayer
          </button>
        </div>
      )}
      {history.isSuccess && rows.length === 0 && <p className="text-slate-600">Aucun ajout de CV pour l’instant.</p>}
      <ul className="divide-y divide-slate-100 text-sm">
        {rows.map((e) => (
          <li key={e.id} className="py-2">
            <span className="font-semibold">{HISTORY_LABEL[e.action]}</span> · {formatDate(e.created_at)}
            {e.author ? ` · ${e.author}` : ''}
            {e.size !== null ? ` · ${size(e.size)}` : ''}
            {e.reason ? <span className="block text-amber-800">{e.reason}</span> : null}
          </li>
        ))}
      </ul>
      {meta && meta.last_page > 1 && (
        <nav aria-label="Pages de l’historique" className="flex items-center gap-2">
          <button type="button" className={button} disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            Précédent
          </button>
          <span className="text-sm">
            Page {meta.current_page} sur {meta.last_page}
          </span>
          <button type="button" className={button} disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>
            Suivant
          </button>
        </nav>
      )}
    </aside>
  )
}
