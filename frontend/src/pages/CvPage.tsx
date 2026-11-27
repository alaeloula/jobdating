import { useState } from 'react'
import { useMe } from '../auth/queries'
import CvActions from '../cv/CvActions'
import CvHistoryPanel from '../cv/CvHistoryPanel'
import CvViewer from '../cv/CvViewer'
import { cvFileName } from '../cv/pdf'
import { CV_HISTORY, CV_UPLOAD, cvLabel } from '../cv/api'
import { useCvOperations } from '../cv/useCvOperations'
import { ApiError } from '../lib/api'
import { EMPTY_FILTERS, useLearners, type Learner, type LearnerFilters } from '../learners/api'
import { useReferentialOptions, type ReferentialOption } from '../referentials/api'

const button = 'min-h-9 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold hover:bg-slate-50 disabled:opacity-50'

/**
 * CV des apprenants (CV-01, CV-04, maquette cme-01 ; T-20) : administrateur et équipe CME. Le dernier
 * CV ajouté remplace le précédent. L'import depuis la plateforme CME (cases à cocher, « Importer (CME) »)
 * arrive avec T-22. Le serveur reste juge des droits.
 */
export default function CvPage() {
  const { data: me } = useMe()
  const canUpload = me?.permissions.includes(CV_UPLOAD) ?? false
  const canHistory = me?.permissions.includes(CV_HISTORY) ?? false
  const [filters, setFilters] = useState<LearnerFilters>({ ...EMPTY_FILTERS, cv: '' })
  const [page, setPage] = useState(1)
  const [history, setHistory] = useState<Learner | null>(null)
  // CV affiché (T-21) : identifiant seulement ; la version vient de la ligne à jour de la liste.
  const [viewing, setViewing] = useState<number | null>(null)
  const ops = useCvOperations()

  const list = useLearners(filters, page)
  const campuses = useReferentialOptions('campus', true)
  const filieres = useReferentialOptions('filieres', true)
  const classes = useReferentialOptions('classes', true)
  const rows = list.data?.data ?? []
  const meta = list.data?.meta
  const withoutCv = list.data?.counts?.without_cv
  const viewed = rows.find((r) => r.id === viewing)

  const change = (next: Partial<LearnerFilters>) => {
    setViewing(null)
    setFilters((f) => ({ ...f, ...next }))
    setPage(1)
  }

  const select = (key: 'campus_id' | 'filiere_id' | 'class_id', label: string, options: ReferentialOption[] | undefined) => (
    <select aria-label={label} value={filters[key]} onChange={(e) => change({ [key]: e.target.value })} className="min-h-9 rounded-full border border-slate-300 bg-white px-3 text-sm">
      <option value="">{label}</option>
      {(options ?? []).map((o) => (
        <option key={o.id} value={o.id}>
          {o.label}
          {o.active ? '' : ' (inactive)'}
        </option>
      ))}
    </select>
  )

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-bold">CV des apprenants</h1>
        <p className="text-slate-600">Le dernier CV ajouté remplace le précédent. Seule la dernière version est conservée.</p>
      </div>

      <div className="flex flex-wrap gap-2">
        <button
          type="button"
          aria-pressed={filters.cv === 'without'}
          onClick={() => change({ cv: filters.cv === 'without' ? '' : 'without' })}
          className={`min-h-9 rounded-full border px-3 text-sm font-semibold ${filters.cv === 'without' ? 'border-teal-700 bg-teal-50 text-teal-800' : 'border-slate-300 bg-white'}`}
        >
          Sans CV{withoutCv !== undefined ? ` (${withoutCv})` : ''}
        </button>
        {select('campus_id', 'Campus', campuses.data)}
        {select('filiere_id', 'Filière', filieres.data)}
        {select('class_id', 'Classe', classes.data)}
        <input
          type="search"
          aria-label="Rechercher un apprenant"
          placeholder="Rechercher (nom, e-mail)"
          value={filters.search}
          onChange={(e) => change({ search: e.target.value })}
          className="min-h-9 min-w-56 flex-1 rounded-lg border border-slate-300 px-3"
        />
      </div>

      <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
        <section className="space-y-3">
          <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table className="w-full text-left text-sm">
              <thead className="text-slate-600">
                <tr>
                  <th className="px-4 py-3">Apprenant</th>
                  <th className="px-4 py-3">Filière</th>
                  <th className="px-4 py-3">CV</th>
                  <th className="px-4 py-3">
                    <span className="sr-only">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {list.isLoading && (
                  <tr>
                    <td colSpan={4} className="px-4 py-6 text-slate-600">
                      Chargement…
                    </td>
                  </tr>
                )}
                {list.isError && (
                  <tr>
                    <td colSpan={4} className="px-4 py-6">
                      <p role="alert" className="text-red-800">
                        {list.error instanceof ApiError ? list.error.message : 'La liste n’a pas pu être chargée.'}
                      </p>
                      <button type="button" className={`${button} mt-2`} onClick={() => void list.refetch()}>
                        Réessayer
                      </button>
                    </td>
                  </tr>
                )}
                {list.isSuccess && rows.length === 0 && (
                  <tr>
                    <td colSpan={4} className="px-4 py-6 text-slate-600">
                      Aucun apprenant.
                    </td>
                  </tr>
                )}
                {rows.map((l) => (
                  <tr key={l.id} className="border-t border-slate-100 align-top">
                    <td className="px-4 py-3">
                      <p className="font-semibold">
                        {l.first_name} {l.last_name}
                      </p>
                      <p className="text-slate-600">
                        {l.campus.label} · {l.school_class.label}
                      </p>
                    </td>
                    <td className="px-4 py-3">{l.filiere.label}</td>
                    <td className={`px-4 py-3 font-semibold ${l.cv ? 'text-teal-800' : 'text-amber-800'}`}>{cvLabel(l.cv)}</td>
                    <td className="px-4 py-3">
                      {l.cv && (
                        <div className="mb-2 flex justify-end">
                          <button type="button" className={button} aria-label={`Voir le CV de ${l.first_name} ${l.last_name}`} onClick={() => setViewing(l.id)}>
                            Voir
                          </button>
                        </div>
                      )}
                      <CvActions learner={l} ops={ops} canUpload={canUpload} onHistory={canHistory ? () => setHistory(l) : undefined} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {meta && meta.last_page > 1 && (
            <nav aria-label="Pagination" className="flex items-center gap-2">
              <button type="button" className={button} disabled={page <= 1} onClick={() => setPage(page - 1)}>
                Précédent
              </button>
              <span className="text-sm">
                Page {meta.current_page} sur {meta.last_page}
              </span>
              <button type="button" className={button} disabled={page >= meta.last_page} onClick={() => setPage(page + 1)}>
                Suivant
              </button>
            </nav>
          )}
        </section>
        <div className="space-y-4">
          {viewed?.cv && (
            <aside aria-label={`CV de ${viewed.first_name} ${viewed.last_name}`} className="space-y-2 rounded-2xl border border-slate-200 bg-white p-4">
              <div className="flex items-start justify-between gap-2">
                <h2 className="font-bold">
                  CV · {viewed.first_name} {viewed.last_name}
                </h2>
                <button type="button" className={button} onClick={() => setViewing(null)}>
                  Fermer
                </button>
              </div>
              <CvViewer
                key={`${viewed.id}:${viewed.cv.version}`}
                owner={viewed.id}
                version={viewed.cv.version}
                title={`${viewed.first_name} ${viewed.last_name}`}
                fileName={cvFileName(viewed.first_name, viewed.last_name)}
                onOutdated={() => void list.refetch()}
              />
            </aside>
          )}
          {history && <CvHistoryPanel key={history.id} learner={history} onClose={() => setHistory(null)} />}
        </div>
      </div>
    </div>
  )
}
