import { useState } from 'react'
import { useReferentialOptions } from '../referentials/api'
import { ApiError } from '../lib/api'
import { PROFILES_WARNING_TEXT, PROFILES_WARNING_TITLE, useCompanyMutations, type Company, type RefValue } from './api'

/**
 * Profils recherchés (RG-ENTR-08, décisions du 16/11 et du 19/11) : administrateur et CME.
 * Choix parmi les valeurs actives ; une valeur déjà associée puis désactivée reste affichée et
 * cochée (« inactive ») tant qu'elle n'est pas décochée. Recruteurs en consultation (T-31).
 */
export default function ProfilesPanel({ company, onClose }: { company: Company; onClose: () => void }) {
  const filieres = useReferentialOptions('filieres')
  const competences = useReferentialOptions('competences')
  const { profiles } = useCompanyMutations()
  const [filiereIds, setFiliereIds] = useState<number[]>(company.filieres.map((f) => f.id))
  const [competenceIds, setCompetenceIds] = useState<number[]>(company.competences.map((c) => c.id))
  const [notice, setNotice] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const merge = (options: { id: number; label: string; active: boolean }[] | undefined, current: RefValue[]) => {
    const active = (options ?? []).map((o) => ({ id: o.id, label: o.label, active: true }))
    const inactive = current.filter((c) => !active.some((a) => a.id === c.id)).map((c) => ({ ...c, active: false }))
    return [...active, ...inactive]
  }

  const toggle = (list: number[], set: (ids: number[]) => void, id: number) =>
    set(list.includes(id) ? list.filter((x) => x !== id) : [...list, id])

  const save = () => {
    setNotice(null)
    setError(null)
    profiles.mutate(
      { id: company.id, filiere_ids: filiereIds, competence_ids: competenceIds },
      {
        onSuccess: () => setNotice('Profils recherchés enregistrés.'),
        onError: (e) => {
          const fields = e instanceof ApiError ? Object.values(e.errors).flat() : []
          setError(fields[0] ?? (e instanceof ApiError ? e.message : 'Une erreur est survenue.'))
        },
      },
    )
  }

  const group = (legend: string, values: RefValue[], selected: number[], set: (ids: number[]) => void) => (
    <fieldset>
      <legend className="font-semibold text-slate-700">{legend}</legend>
      {values.length === 0 ? (
        <p className="mt-1 text-sm text-slate-500">Aucune valeur dans le référentiel.</p>
      ) : (
        <div className="mt-2 flex flex-wrap gap-2">
          {values.map((v) => (
            <label key={v.id} className={`flex items-center gap-2 rounded-lg border px-3 py-1 text-sm ${selected.includes(v.id) ? 'border-indigo-600 bg-indigo-50' : 'border-slate-300'}`}>
              <input type="checkbox" checked={selected.includes(v.id)} onChange={() => toggle(selected, set, v.id)} />
              {v.label}
              {!v.active && <span className="text-xs text-slate-500">(inactive)</span>}
            </label>
          ))}
        </div>
      )}
    </fieldset>
  )

  return (
    <aside className="h-fit space-y-4 rounded-2xl border border-slate-200 bg-white p-5">
      <h2 className="text-lg font-bold">Profils recherchés · {company.name}</h2>
      <div role="note" className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
        <p className="font-bold">{PROFILES_WARNING_TITLE}</p>
        <p>{PROFILES_WARNING_TEXT}</p>
      </div>
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
      {group('Filières recherchées', merge(filieres.data, company.filieres), filiereIds, setFiliereIds)}
      {group('Compétences recherchées', merge(competences.data, company.competences), competenceIds, setCompetenceIds)}
      <div className="flex gap-2">
        <button type="button" onClick={save} disabled={profiles.isPending} className="rounded-xl bg-indigo-600 px-4 py-2 font-bold text-white disabled:opacity-60">
          {profiles.isPending ? 'Enregistrement…' : 'Enregistrer les profils'}
        </button>
        <button type="button" onClick={onClose} className="rounded-xl border border-slate-300 px-4 py-2 font-semibold">
          Fermer
        </button>
      </div>

      <section aria-labelledby="recruiters-title">
        <h3 id="recruiters-title" className="font-semibold text-slate-700">
          Recruteurs enregistrés
        </h3>
        {company.recruiters.length === 0 ? (
          <p className="mt-1 text-sm text-slate-500">Aucun recruteur enregistré pour l’instant (ils sont ajoutés par l’entreprise depuis ses évaluations).</p>
        ) : (
          <ul className="mt-2 space-y-1 text-sm">
            {company.recruiters.map((r) => (
              <li key={r.id}>
                <span className="font-semibold">{r.name}</span>
                {r.job_title && ` · ${r.job_title}`} · {r.email} · {r.phone}
              </li>
            ))}
          </ul>
        )}
      </section>
    </aside>
  )
}
