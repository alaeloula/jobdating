import { useRef, useState } from 'react'
import { useMe } from '../auth/queries'
import { ApiError } from '../lib/api'
import { useReferentialOptions, type ReferentialOption } from '../referentials/api'
import CompetencesPanel from '../learners/CompetencesPanel'
import LearnerForm from '../learners/LearnerForm'
import {
  EMPTY_FILTERS,
  countSelection,
  fetchLearner,
  filtersBody,
  resetLearnerPassword,
  runBulk,
  useLearnerMutations,
  useLearners,
  type BulkAction,
  type Learner,
  type LearnerFilters,
  type Selection,
} from '../learners/api'

const button = 'min-h-9 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold hover:bg-slate-50 disabled:opacity-50'
const LEARNERS_UPDATE = 'learners.update'
const VIEW_CNIE = 'learners.view_cnie'
const COMPETENCES_MANAGE = 'learners.competences.manage'

type Panel = { kind: 'new' } | { kind: 'edit'; learner: Learner } | { kind: 'competences'; learner: Learner } | null

interface Bulk {
  action: BulkAction
  competenceId?: number
  counts?: { selected: number; eligible: number }
  error?: string
  pending: boolean
}

const STATUS_LABEL = { actif: 'Actif', desactive: 'Désactivé', supprime: 'Supprimé' } as const

/**
 * Apprenants (ADM-03, T-12, maquette admin-06). Administrateur : gestion complète. CME : lecture et
 * compétences. Direction : lecture seule. CNIE visible par les trois (décision du 19/11), jamais par
 * une entreprise. Le serveur reste juge des droits ; cet écran ne fait que les refléter.
 */
export default function LearnersPage() {
  const { data: me } = useMe()
  const canManage = me?.permissions.includes(LEARNERS_UPDATE) ?? false
  const canCompetences = me?.permissions.includes(COMPETENCES_MANAGE) ?? false
  const canSelect = canManage || canCompetences
  const canSeeCnie = me?.permissions.includes(VIEW_CNIE) ?? false

  const [filters, setFilters] = useState<LearnerFilters>(EMPTY_FILTERS)
  const [page, setPage] = useState(1)
  const [checked, setChecked] = useState<number[]>([])
  const [wholeFilter, setWholeFilter] = useState<Extract<Selection, { mode: 'filter' }> | null>(null)
  const [panel, setPanel] = useState<Panel>(null)
  const [bulk, setBulk] = useState<Bulk | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [revealed, setRevealed] = useState<{ name: string; password: string } | null>(null)
  // Numéro de la demande de comptage en cours : une réponse arrivée après une annulation, un
  // changement de sélection ou une nouvelle demande est ignorée (revue Codex T-12, constat 3).
  const bulkRequest = useRef(0)
  const closeBulk = () => {
    bulkRequest.current += 1
    setBulk(null)
  }

  const list = useLearners(filters, page)
  const { action, refresh } = useLearnerMutations()
  const campuses = useReferentialOptions('campus', true)
  const promotions = useReferentialOptions('promotions', true)
  const classes = useReferentialOptions('classes', true)
  const filieres = useReferentialOptions('filieres', true)
  const competences = useReferentialOptions('competences', true)
  const activeCompetences = useReferentialOptions('competences')

  const rows = list.data?.data ?? []
  const meta = list.data?.meta
  const selection: Selection | null = wholeFilter ?? (checked.length > 0 ? { mode: 'ids', ids: checked } : null)
  const selectedCount = wholeFilter ? wholeFilter.total : checked.length

  /** Toute modification de recherche, de filtre ou de page vide la sélection (plan T-12 v2, §5). */
  const changeFilters = (next: Partial<LearnerFilters>) => {
    setFilters((f) => ({ ...f, ...next }))
    setPage(1)
    setChecked([])
    setWholeFilter(null)
    closeBulk()
  }
  const changePage = (next: number) => {
    setPage(next)
    setChecked([])
    setWholeFilter(null)
    closeBulk()
  }

  const say = (text: string) => {
    setError(null)
    setNotice(text)
  }
  const fail = (e: unknown) => {
    setNotice(null)
    setError(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez dans quelques instants.')
  }

  const openPanel = (next: Panel) => {
    setRevealed(null)
    setPanel(next)
  }

  const edit = async (learner: Learner) => {
    try {
      // La fiche est relue à l'ouverture (valeurs à jour, téléphone et identifiant CME).
      openPanel({ kind: 'edit', learner: await fetchLearner(learner.id) })
    } catch (e) {
      fail(e)
    }
  }

  const reset = async (learner: Learner) => {
    try {
      const result = await resetLearnerPassword(learner.id)
      setPanel(null)
      setRevealed({ name: `${learner.first_name} ${learner.last_name}`, password: result.password })
      say(`Mot de passe de ${learner.first_name} ${learner.last_name} réinitialisé et envoyé par e-mail.`)
    } catch (e) {
      fail(e)
    }
  }

  const rowAction = (learner: Learner, name: 'deactivate' | 'reactivate' | 'restore' | 'delete', message: string) =>
    action.mutate({ id: learner.id, name }, { onSuccess: () => say(message), onError: fail })

  const openBulk = async (next: BulkAction, competenceId?: number) => {
    if (!selection) return
    const request = ++bulkRequest.current
    setBulk({ action: next, competenceId, pending: true })
    if (next === 'add_competence' && !competenceId) {
      setBulk({ action: next, pending: false })
      return
    }
    try {
      const counts = await countSelection(selection, next, competenceId)
      if (request !== bulkRequest.current) return
      setBulk({ action: next, competenceId, counts, pending: false })
    } catch (e) {
      if (request !== bulkRequest.current) return
      setBulk({ action: next, competenceId, pending: false, error: e instanceof ApiError ? e.message : 'Une erreur est survenue.' })
    }
  }

  const confirmBulk = async () => {
    if (!selection || !bulk?.counts) return
    const request = ++bulkRequest.current
    setBulk({ ...bulk, pending: true, error: undefined })
    try {
      const changed = await runBulk(selection, bulk.action, bulk.counts.eligible, bulk.competenceId)
      closeBulk()
      setChecked([])
      setWholeFilter(null)
      await refresh()
      say(bulk.action === 'deactivate' ? `${changed} apprenant(s) désactivé(s).` : `Compétence ajoutée à ${changed} apprenant(s).`)
    } catch (e) {
      if (request !== bulkRequest.current) return
      // 409 : nouveau nombre affiché, nouvelle confirmation EXPLICITE (jamais de relance automatique).
      if (e instanceof ApiError && e.code === 'selection_changed' && typeof e.payload?.eligible === 'number') {
        setBulk({ ...bulk, pending: false, counts: { selected: bulk.counts.selected, eligible: e.payload.eligible }, error: e.message })
      } else {
        setBulk({ ...bulk, pending: false, error: e instanceof ApiError ? e.message : 'Une erreur est survenue.' })
      }
    }
  }

  const filterSelect = (key: 'campus_id' | 'promotion_id' | 'class_id' | 'filiere_id' | 'competence_id', label: string, options: ReferentialOption[] | undefined) => (
    <label className="text-sm">
      <select aria-label={`Filtre ${label}`} value={filters[key]} onChange={(e) => changeFilters({ [key]: e.target.value })} className="min-h-9 rounded-full border border-slate-300 bg-white px-3">
        <option value="">{label} : tous</option>
        {(options ?? []).map((o) => (
          <option key={o.id} value={o.id}>
            {o.label}
            {o.active ? '' : ' (inactive)'}
          </option>
        ))}
      </select>
    </label>
  )

  const statuses: [LearnerFilters['status'], string][] = [
    ['actif', 'Actifs'],
    ['desactive', 'Désactivés'],
    ...(canManage ? ([['supprime', 'Supprimés']] as [LearnerFilters['status'], string][]) : []),
  ]
  const pageIds = rows.filter((r) => r.status !== 'supprime').map((r) => r.id)
  const allChecked = pageIds.length > 0 && pageIds.every((id) => checked.includes(id))

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold">Apprenants</h1>
          <p className="text-slate-600">
            {meta ? `${meta.total} apprenant(s).` : ''} {canSeeCnie ? 'La CNIE n’est jamais visible par les entreprises.' : ''}
          </p>
        </div>
        {canManage && (
          <button type="button" onClick={() => openPanel({ kind: 'new' })} className="min-h-10 rounded-lg bg-indigo-600 px-4 font-bold text-white hover:bg-indigo-700">
            Ajouter un apprenant
          </button>
        )}
      </div>

      {notice && (
        <p role="status" className="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-green-800">
          {notice}
        </p>
      )}
      {error && (
        <p role="alert" className="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-red-800">
          {error}
        </p>
      )}
      {revealed && (
        <div role="dialog" aria-label="Mot de passe généré" className="rounded-lg border border-indigo-300 bg-indigo-50 p-4">
          <p className="font-semibold">Mot de passe de {revealed.name}</p>
          <p className="mt-2 font-mono text-xl tracking-wider" data-testid="generated-password">
            {revealed.password}
          </p>
          <p className="mt-2 text-sm text-slate-700">Il ne sera plus affiché après fermeture. Il a aussi été envoyé à l’e-mail professionnel de l’apprenant.</p>
          <div className="mt-3 flex gap-2">
            <button type="button" className={button} onClick={() => void navigator.clipboard?.writeText(revealed.password)}>
              Copier
            </button>
            <button type="button" className={button} onClick={() => setRevealed(null)}>
              Fermer
            </button>
          </div>
        </div>
      )}

      <div className="flex flex-wrap gap-2">
        <input
          type="search"
          aria-label="Rechercher un apprenant"
          placeholder={canSeeCnie ? 'Rechercher (nom, e-mail, CNIE)' : 'Rechercher (nom, e-mail)'}
          value={filters.search}
          onChange={(e) => changeFilters({ search: e.target.value })}
          className="min-h-9 min-w-56 flex-1 rounded-lg border border-slate-300 px-3"
        />
        {statuses.map(([value, label]) => (
          <button
            key={value}
            type="button"
            aria-pressed={filters.status === value}
            onClick={() => changeFilters({ status: value })}
            className={`min-h-9 rounded-full border px-3 text-sm font-semibold ${filters.status === value ? 'border-indigo-700 bg-indigo-50 text-indigo-800' : 'border-slate-300 bg-white'}`}
          >
            {label}
          </button>
        ))}
        {filterSelect('campus_id', 'Campus', campuses.data)}
        {filterSelect('promotion_id', 'Promotion', promotions.data)}
        {filterSelect('class_id', 'Classe', classes.data)}
        {filterSelect('filiere_id', 'Filière', filieres.data)}
        {filterSelect('competence_id', 'Compétence', competences.data)}
      </div>

      {canSelect && selection && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-indigo-200 bg-indigo-50 p-4" role="region" aria-label="Sélection">
          <p className="font-semibold text-indigo-900">
            {wholeFilter ? `Tout le filtre : ${selectedCount} apprenant(s) sélectionné(s).` : `${selectedCount} apprenant(s) sélectionné(s).`}
          </p>
          <div className="flex flex-wrap gap-2">
            {canCompetences && (
              <button type="button" className={button} onClick={() => void openBulk('add_competence')}>
                Ajouter une compétence à la sélection
              </button>
            )}
            {canManage && (
              <button type="button" className="min-h-9 rounded-lg bg-red-700 px-3 text-sm font-bold text-white hover:bg-red-800" onClick={() => void openBulk('deactivate')}>
                Désactiver la sélection
              </button>
            )}
            <button type="button" className={button} onClick={() => { setChecked([]); setWholeFilter(null); closeBulk() }}>
              Vider la sélection
            </button>
          </div>
          {bulk && (
            <div role="dialog" aria-label="Confirmer l’action en masse" className="w-full rounded-lg border border-slate-300 bg-white p-4">
              {bulk.action === 'add_competence' && (
                <label className="block text-sm font-semibold">
                  Compétence à ajouter
                  <select
                    className="mt-1 block min-h-9 rounded-lg border border-slate-300 px-3"
                    value={bulk.competenceId ?? ''}
                    onChange={(e) => void openBulk('add_competence', Number(e.target.value) || undefined)}
                  >
                    <option value="">Choisir…</option>
                    {(activeCompetences.data ?? []).map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.label}
                      </option>
                    ))}
                  </select>
                </label>
              )}
              {bulk.error && (
                <p role="alert" className="mt-2 text-sm text-red-800">
                  {bulk.error}
                </p>
              )}
              {bulk.counts && (
                <>
                  <p className="mt-2" data-testid="bulk-counts">
                    {bulk.counts.selected} sélectionné(s), dont {bulk.counts.eligible} concerné(s).
                  </p>
                  <p className="font-semibold">
                    {bulk.action === 'deactivate'
                      ? `${bulk.counts.eligible} compte(s) vont être désactivés.`
                      : `La compétence sera ajoutée à ${bulk.counts.eligible} apprenant(s).`}
                  </p>
                </>
              )}
              <div className="mt-3 flex gap-2">
                <button
                  type="button"
                  disabled={bulk.pending || !bulk.counts || bulk.counts.eligible === 0}
                  onClick={() => void confirmBulk()}
                  className="min-h-9 rounded-lg bg-indigo-600 px-3 text-sm font-bold text-white hover:bg-indigo-700 disabled:opacity-50"
                >
                  Confirmer
                </button>
                <button type="button" className={button} onClick={closeBulk}>
                  Annuler
                </button>
              </div>
            </div>
          )}
        </div>
      )}

      <div className={`grid gap-4 ${panel ? 'lg:grid-cols-[minmax(0,1fr)_26rem]' : ''}`}>
        <section className="space-y-3">
          {canSelect && meta && meta.total > 0 && !wholeFilter && (
            <button type="button" className={button} onClick={() => { setChecked([]); closeBulk(); setWholeFilter({ mode: 'filter', filters: filtersBody(filters), total: meta.total }) }}>
              Sélectionner les {meta.total} apprenants du filtre
            </button>
          )}
          <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table className="w-full text-left text-sm">
              <thead className="bg-slate-50 text-slate-600">
                <tr>
                  {canSelect && (
                    <th className="px-4 py-3">
                      <input
                        type="checkbox"
                        aria-label="Sélectionner la page"
                        checked={allChecked}
                        disabled={wholeFilter !== null}
                        onChange={() => {
                          closeBulk()
                          setChecked(allChecked ? [] : pageIds)
                        }}
                      />
                    </th>
                  )}
                  <th className="px-4 py-3">Apprenant</th>
                  {canSeeCnie && <th className="px-4 py-3">CNIE</th>}
                  <th className="px-4 py-3">Filière</th>
                  <th className="px-4 py-3">Classe</th>
                  <th className="px-4 py-3">Promotion</th>
                  <th className="px-4 py-3">Statut</th>
                  {(canManage || canCompetences) && <th className="px-4 py-3">Actions</th>}
                </tr>
              </thead>
              <tbody>
                {list.isLoading && (
                  <tr>
                    <td colSpan={8} className="px-4 py-6 text-slate-600">
                      Chargement…
                    </td>
                  </tr>
                )}
                {list.isError && (
                  <tr>
                    <td colSpan={8} className="px-4 py-6">
                      <p role="alert" className="text-red-800">
                        {list.error instanceof ApiError ? list.error.message : 'La liste n’a pas pu être chargée.'}
                      </p>
                      <button type="button" className={`${button} mt-2`} onClick={() => void list.refetch()}>
                        Réessayer
                      </button>
                    </td>
                  </tr>
                )}
                {!list.isLoading && !list.isError && rows.length === 0 && (
                  <tr>
                    <td colSpan={8} className="px-4 py-6 text-slate-600">
                      Aucun apprenant.
                    </td>
                  </tr>
                )}
                {rows.map((l) => (
                  <tr key={l.id} className="border-t border-slate-100 align-top">
                    {canSelect && (
                      <td className="px-4 py-3">
                        <input
                          type="checkbox"
                          aria-label={`Sélectionner ${l.first_name} ${l.last_name}`}
                          disabled={wholeFilter !== null || l.status === 'supprime'}
                          checked={wholeFilter !== null || checked.includes(l.id)}
                          onChange={() => {
                            closeBulk()
                            setChecked((c) => (c.includes(l.id) ? c.filter((x) => x !== l.id) : [...c, l.id]))
                          }}
                        />
                      </td>
                    )}
                    <td className="px-4 py-3">
                      <p className="font-semibold">
                        {l.first_name} {l.last_name}
                      </p>
                      <p className="text-slate-600">{l.email}</p>
                      {l.competences.length > 0 && (
                        <ul aria-label={`Compétences de ${l.first_name} ${l.last_name}`} className="mt-1 flex flex-wrap gap-1">
                          {l.competences.map((c) => (
                            <li key={c.id} className={`rounded-full px-2 py-0.5 text-xs ${c.active ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600 line-through'}`}>
                              {c.label}
                            </li>
                          ))}
                        </ul>
                      )}
                    </td>
                    {canSeeCnie && <td className="px-4 py-3 font-mono">{l.cnie}</td>}
                    <td className="px-4 py-3">{l.filiere.label}</td>
                    <td className="px-4 py-3">{l.school_class.label}</td>
                    <td className="px-4 py-3">{l.promotion.label}</td>
                    <td className="px-4 py-3">{STATUS_LABEL[l.status]}</td>
                    {(canManage || canCompetences) && (
                      <td className="px-4 py-3">
                        <div className="flex flex-wrap justify-end gap-2">
                          {canManage && l.status !== 'supprime' && (
                            <>
                              <button type="button" className={button} onClick={() => void edit(l)}>
                                Modifier
                              </button>
                              {l.status === 'actif' && (
                                <button type="button" className={button} onClick={() => void reset(l)}>
                                  Réinitialiser le mot de passe
                                </button>
                              )}
                              {l.status === 'actif' ? (
                                <button type="button" className={button} onClick={() => rowAction(l, 'deactivate', `${l.first_name} ${l.last_name} est désactivé(e).`)}>
                                  Désactiver
                                </button>
                              ) : (
                                <button type="button" className={button} onClick={() => rowAction(l, 'reactivate', `${l.first_name} ${l.last_name} est réactivé(e).`)}>
                                  Réactiver
                                </button>
                              )}
                              <button type="button" className={button} onClick={() => rowAction(l, 'delete', `${l.first_name} ${l.last_name} est supprimé(e) ; la fiche peut être restaurée.`)}>
                                Supprimer
                              </button>
                            </>
                          )}
                          {canManage && l.status === 'supprime' && (
                            <button type="button" className={button} onClick={() => rowAction(l, 'restore', `${l.first_name} ${l.last_name} est restauré(e).`)}>
                              Restaurer
                            </button>
                          )}
                          {canCompetences && l.status !== 'supprime' && (
                            <button type="button" className={button} onClick={() => openPanel({ kind: 'competences', learner: l })}>
                              Compétences
                            </button>
                          )}
                        </div>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {meta && meta.last_page > 1 && (
            <nav aria-label="Pagination" className="flex items-center gap-2">
              <button type="button" className={button} disabled={page <= 1} onClick={() => changePage(page - 1)}>
                Précédent
              </button>
              <span className="text-sm">
                Page {meta.current_page} sur {meta.last_page}
              </span>
              <button type="button" className={button} disabled={page >= meta.last_page} onClick={() => changePage(page + 1)}>
                Suivant
              </button>
            </nav>
          )}
        </section>

        {panel?.kind === 'new' && (
          <LearnerForm
            learner={null}
            onCancel={() => setPanel(null)}
            onDone={say}
            onCreated={(learner, password) => {
              setPanel(null)
              setRevealed({ name: `${learner.first_name} ${learner.last_name}`, password })
              say(`Apprenant ${learner.first_name} ${learner.last_name} créé. Le mot de passe est envoyé à son e-mail professionnel.`)
            }}
          />
        )}
        {panel?.kind === 'edit' && (
          <LearnerForm
            key={panel.learner.id}
            learner={panel.learner}
            onCancel={() => setPanel(null)}
            onCreated={() => undefined}
            onDone={(text) => {
              setPanel(null)
              say(text)
            }}
          />
        )}
        {panel?.kind === 'competences' && <CompetencesPanel key={panel.learner.id} learner={panel.learner} onClose={() => setPanel(null)} />}
      </div>
    </div>
  )
}
