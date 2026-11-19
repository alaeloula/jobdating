import { useState } from 'react'
import { ApiError } from '../lib/api'
import { useReferentialOptions } from '../referentials/api'
import { useLearnerMutations, type Learner, type RefValue } from './api'

/**
 * Compétences d'un apprenant (décision du 16/11) : administrateur et CME, sans niveau. Choix parmi
 * les compétences actives ; une compétence déjà associée puis désactivée reste cochée « (inactive) ».
 */
export default function CompetencesPanel({ learner, onClose }: { learner: Learner; onClose: () => void }) {
  const options = useReferentialOptions('competences')
  const { competences } = useLearnerMutations()
  const [selected, setSelected] = useState<number[]>(learner.competences.map((c) => c.id))
  const [notice, setNotice] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const active: RefValue[] = (options.data ?? []).map((o) => ({ id: o.id, label: o.label, active: true }))
  const values = [...active, ...learner.competences.filter((c) => !active.some((a) => a.id === c.id)).map((c) => ({ ...c, active: false }))]

  const toggle = (id: number) => {
    setError(null)
    setSelected((list) => (list.includes(id) ? list.filter((x) => x !== id) : [...list, id]))
  }

  const save = () => {
    setNotice(null)
    setError(null)
    competences.mutate(
      { id: learner.id, competence_ids: selected },
      {
        onSuccess: () => setNotice('Compétences enregistrées.'),
        onError: (e) => {
          const fields = e instanceof ApiError ? Object.values(e.errors).flat() : []
          setError(fields[0] ?? (e instanceof ApiError ? e.message : 'Une erreur est survenue.'))
        },
      },
    )
  }

  return (
    <aside className="h-fit space-y-4 rounded-2xl border border-slate-200 bg-white p-5">
      <h2 className="text-lg font-bold">
        Compétences · {learner.first_name} {learner.last_name}
      </h2>
      {notice && (
        <p role="status" className="rounded-lg border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-800">
          {notice}
        </p>
      )}
      {error && (
        <p role="alert" className="rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">
          {error}
        </p>
      )}
      {values.length === 0 ? (
        <p className="text-sm text-slate-600">Aucune compétence dans le référentiel.</p>
      ) : (
        <fieldset>
          <legend className="sr-only">Compétences</legend>
          <div className="flex flex-wrap gap-2">
            {values.map((v) => (
              <label key={v.id} className={`flex items-center gap-2 rounded-lg border px-3 py-1 text-sm ${selected.includes(v.id) ? 'border-indigo-600 bg-indigo-50' : 'border-slate-300'}`}>
                <input type="checkbox" checked={selected.includes(v.id)} onChange={() => toggle(v.id)} />
                {v.label}
                {!v.active && <span className="text-xs text-slate-600">(inactive)</span>}
              </label>
            ))}
          </div>
        </fieldset>
      )}
      <div className="flex justify-end gap-2">
        <button type="button" onClick={onClose} className="min-h-10 rounded-lg border border-slate-300 bg-white px-4 font-semibold hover:bg-slate-50">
          Fermer
        </button>
        <button type="button" onClick={save} disabled={competences.isPending} className="min-h-10 rounded-lg bg-indigo-600 px-4 font-bold text-white hover:bg-indigo-700 disabled:opacity-60">
          Enregistrer les compétences
        </button>
      </div>
    </aside>
  )
}
