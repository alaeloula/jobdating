import { useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router'
import ImportSheets from '../companies/ImportSheets'
import {
  confirmCompanyImport,
  deleteImportCredentials,
  fetchCompanyImport,
  fetchImportSheets,
  markImportPrinted,
  previewCompanyImport,
  useCompanyImportHistory,
  useExpected,
  type CompanyImport,
  type CompanyImportChoice,
  type CompanyImportPreview,
  type ImportSheets as Sheets,
  type Signaled,
} from '../companies/importApi'
import { useEventOptions } from '../events/api'
import { ApiError } from '../lib/api'
import { newKey } from '../learners/importApi'

const button = 'min-h-9 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold hover:bg-slate-50 disabled:opacity-50'
const input = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2'
const POLL_MS = 3000

const STATUS_LABEL: Record<CompanyImport['status'], string> = { en_attente: 'En attente', en_cours: 'En cours', termine: 'Terminé', echec: 'Échec' }

/** Nombre prévu ou réalisé selon l'état (revue Codex T-64, constat 3) : « créées » seulement après succès. */
const CREATED_LABEL: Record<CompanyImport['status'], string> = { en_attente: 'à créer', en_cours: 'à créer', termine: 'créées', echec: 'prévues, aucune créée' }

function message(e: unknown): string {
  if (e instanceof ApiError) return Object.values(e.errors).flat()[0] ?? e.message
  return e instanceof Error ? e.message : 'Une erreur est survenue. Réessayez dans quelques instants.'
}

function SignaledList({ items }: { items: Signaled[] }) {
  if (items.length === 0) return null
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <h2 className="font-bold">Lignes signalées ({items.length})</h2>
      <ul className="mt-2 space-y-1 text-sm">
        {items.map((s) => (
          <li key={`${s.line}-${s.reason}`}>
            <span className="font-semibold">Ligne {s.line}</span>
            {s.name ? ` · ${s.name}` : ''} <span className="text-amber-800">{s.reason}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}

/**
 * Import des entreprises (ADM-04, T-64) : classeur de suivi de l'équipe CME tel quel, aperçu,
 * confirmation traitée en file d'attente, rapport, fiches de connexion imprimables en une fois.
 */
export default function CompanyImportPage() {
  const expected = useExpected()
  const options = useEventOptions()
  const [historyPage, setHistoryPage] = useState(1)
  const history = useCompanyImportHistory(historyPage)
  const queryClient = useQueryClient()

  const [choice, setChoice] = useState<CompanyImportChoice>({ eventId: '', file: null })
  const [preview, setPreview] = useState<CompanyImportPreview | null>(null)
  const [key, setKey] = useState<string | null>(null)
  const [current, setCurrent] = useState<CompanyImport | null>(null)
  const [sheets, setSheets] = useState<Sheets | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [pollError, setPollError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [pending, setPending] = useState(false)
  const [loadingSheets, setLoadingSheets] = useState(false)
  const request = useRef(0)
  const view = useRef(0)
  // Numéro de l'affichage des fiches : masquer, supprimer ou changer d'import l'invalide, et une
  // réponse plus ancienne ne réaffiche jamais les mots de passe (revue Codex T-64, constat 1).
  const sheetsRequest = useRef(0)
  // Imports dont la suppression des mots de passe est en cours, suivis indépendamment de l'import
  // affiché : fiches bloquées et second déclenchement ignoré (contre-revue Codex T-64, N1 et N2).
  const deletingIds = useRef(new Set<number>())
  const [deleting, setDeleting] = useState<number[]>([])
  const currentIdRef = useRef<number | null>(null)
  useEffect(() => {
    currentIdRef.current = current?.id ?? null
  }, [current])
  const fileInput = useRef<HTMLInputElement>(null)

  const events = options.data?.events ?? []
  const eventId = choice.eventId !== '' && events.some((e) => String(e.id) === choice.eventId) ? choice.eventId : String(options.data?.current_id ?? '')
  const chosen: CompanyImportChoice = { ...choice, eventId }

  /** Tout changement efface l'aperçu et la clé de confirmation. */
  const change = (next: Partial<CompanyImportChoice>) => {
    request.current += 1
    setPending(false)
    setChoice((c) => ({ ...c, eventId, ...next }))
    setPreview(null)
    setKey(null)
    setError(null)
    setNotice(null)
  }

  const runPreview = async (reason: string | null = null) => {
    const id = ++request.current
    setError(reason)
    setPending(true)
    try {
      const result = await previewCompanyImport(chosen)
      if (id !== request.current) return
      setPreview(result)
      setKey(newKey())
    } catch (e) {
      if (id === request.current) setError(message(e))
    } finally {
      if (id === request.current) setPending(false)
    }
  }

  const runConfirm = async () => {
    if (!preview || !key || pending) return
    const generation = ++view.current
    setError(null)
    setPending(true)
    try {
      const created = await confirmCompanyImport(chosen, preview.fingerprint, key)
      if (generation === view.current) setCurrent(created)
      else void queryClient.invalidateQueries({ queryKey: ['company-imports'] })
    } catch (e) {
      if (generation !== view.current) return
      if (e instanceof ApiError && e.code === 'preview_changed') {
        setKey(null)
        void runPreview(e.message)
      } else {
        setError(message(e))
      }
    } finally {
      if (generation === view.current) setPending(false)
    }
  }

  const currentId = current?.id
  const running = current !== null && (current.status === 'en_attente' || current.status === 'en_cours')
  useEffect(() => {
    if (!running || currentId === undefined) return
    const generation = view.current
    let sent = 0
    let newest = 0
    const timer = setInterval(() => {
      const poll = ++sent
      fetchCompanyImport(currentId)
        .then((fresh) => {
          if (generation !== view.current || poll < newest) return
          newest = poll
          setPollError(null)
          setCurrent(fresh)
          if (fresh.status === 'termine' || fresh.status === 'echec') void queryClient.invalidateQueries({ queryKey: ['company-imports'] })
        })
        .catch(() => {
          if (generation === view.current && poll >= newest) setPollError('Le suivi de l’import est momentanément indisponible ; nouvel essai dans quelques secondes.')
        })
    }, POLL_MS)
    return () => clearInterval(timer)
  }, [running, currentId, queryClient])

  /** Efface les fiches de l'écran et ignore toute réponse de fiches encore en vol. */
  const hideSheets = () => {
    sheetsRequest.current += 1
    setSheets(null)
    setLoadingSheets(false)
  }

  /** Change d'import affiché : les fiches (mots de passe) sont effacées de l'écran. */
  const leave = () => {
    request.current += 1
    view.current += 1
    hideSheets()
    setPending(false)
    setPollError(null)
    setPreview(null)
    setKey(null)
    setError(null)
    setNotice(null)
  }

  const restart = () => {
    leave()
    setCurrent(null)
    setChoice((c) => ({ ...c, file: null }))
    if (fileInput.current) fileInput.current.value = ''
  }

  const openReport = async (id: number) => {
    leave()
    setCurrent(null)
    const generation = view.current
    try {
      const report = await fetchCompanyImport(id)
      if (generation === view.current) setCurrent(report)
    } catch (e) {
      if (generation === view.current) setError(message(e))
    }
  }

  const showSheets = async (id: number) => {
    if (loadingSheets || deletingIds.current.has(id)) return
    const generation = view.current
    const requestId = ++sheetsRequest.current
    const fresh = () => generation === view.current && requestId === sheetsRequest.current && !deletingIds.current.has(id)
    setError(null)
    setNotice(null)
    setLoadingSheets(true)
    try {
      const data = await fetchImportSheets(id)
      if (fresh()) setSheets(data)
    } catch (e) {
      if (fresh()) setError(message(e))
    } finally {
      if (fresh()) setLoadingSheets(false)
    }
  }

  const printed = async (id: number) => {
    const generation = view.current
    setError(null)
    try {
      const result = await markImportPrinted(id)
      if (generation !== view.current) return
      setNotice(`${result.marked} fiche(s) marquée(s) comme imprimée(s)${result.skipped > 0 ? `, ${result.skipped} ignorée(s) (fiche caduque)` : ''}.`)
      void queryClient.invalidateQueries({ queryKey: ['companies'] })
      void queryClient.invalidateQueries({ queryKey: ['events'] })
    } catch (e) {
      if (generation === view.current) setError(message(e))
    }
  }

  const remove = async (item: CompanyImport) => {
    if (deletingIds.current.has(item.id)) return
    if (!window.confirm('Supprimer définitivement les mots de passe de cet import ? Les fiches ne pourront plus être imprimées.')) return
    const generation = view.current
    deletingIds.current.add(item.id)
    setDeleting([...deletingIds.current])
    setError(null)
    setNotice(null)
    // Les fiches affichées ou en cours de chargement sont abandonnées AVANT la suppression.
    hideSheets()
    try {
      await deleteImportCredentials(item.id)
      // Quelle que soit la vue (l'import a pu être rouvert entre-temps), aucune fiche de cet import
      // ne reste affichée et son état local est actualisé. Les actions sont retirées tout de suite,
      // sans attendre la relecture, qui peut échouer (vérification Codex T-64, V1).
      setCurrent((c) => (c?.id === item.id ? { ...c, has_credentials: false, credentials_deleted: true } : c))
      if (currentIdRef.current === item.id) {
        hideSheets()
        const shown = view.current
        void fetchCompanyImport(item.id)
          .then((fresh) => {
            if (shown === view.current) setCurrent(fresh)
          })
          .catch(() => undefined)
      }
      if (generation === view.current) setNotice('Mots de passe de l’import supprimés.')
      void queryClient.invalidateQueries({ queryKey: ['company-imports'] })
    } catch (e) {
      if (generation === view.current) setError(message(e))
    } finally {
      deletingIds.current.delete(item.id)
      setDeleting([...deletingIds.current])
    }
  }

  const ready = eventId !== '' && choice.file !== null
  const noEvent = options.isSuccess && events.length === 0

  return (
    <div className="space-y-4">
      <div>
        <Link to="/entreprises" className="text-sm text-indigo-700 underline">
          ← Entreprises participantes
        </Link>
        <h1 className="mt-1 text-2xl font-bold">Import des entreprises</h1>
        <p className="text-slate-600">
          Déposez le classeur de suivi tel quel. Seules les lignes de la feuille « {expected.data?.sheet ?? 'Entreprises Invitées'} » dont « {expected.data?.confirmation ?? 'Confirmation par mail'} » vaut « Oui » sont importées, et seul le nom (colonne « {expected.data?.name ?? 'Entreprises'} ») est repris. Aucune autre colonne n’est lue. Aucun e-mail n’est envoyé.
        </p>
      </div>

      {error && (
        <p role="alert" className="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-red-800">
          {error}
        </p>
      )}
      {pollError && (
        <p role="alert" className="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-amber-900">
          {pollError}
        </p>
      )}
      {notice && (
        <p role="status" className="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-green-800">
          {notice}
        </p>
      )}

      <section className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 md:grid-cols-2">
        <label className="block font-semibold text-slate-700">
          Événement
          <select value={eventId} disabled={running || !options.isSuccess} onChange={(e) => change({ eventId: e.target.value })} className={input}>
            <option value="">{options.isPending ? 'Chargement…' : 'Choisir…'}</option>
            {events.map((e) => (
              <option key={e.id} value={e.id}>
                {e.name}
                {e.is_current ? ' (courant)' : ''}
              </option>
            ))}
          </select>
          {options.isError && (
            <span role="alert" className="mt-1 block text-sm font-normal text-red-700">
              Les événements n’ont pas pu être chargés.{' '}
              <button type="button" className="underline" onClick={() => void options.refetch()}>
                Réessayer
              </button>
            </span>
          )}
          {noEvent && <span className="mt-1 block text-sm font-normal text-slate-700">Aucun événement disponible : créez-en un dans « Événement et programme ».</span>}
        </label>
        <label className="block font-semibold text-slate-700">
          Classeur (.xlsx)
          <input
            ref={fileInput}
            type="file"
            accept=".xlsx"
            disabled={running}
            onChange={(e) => change({ file: e.target.files?.[0] ?? null })}
            className="mt-1 block w-full text-sm"
          />
        </label>
        <div className="md:col-span-2">
          <button type="button" className={button} disabled={!ready || pending || running} onClick={() => void runPreview()}>
            Voir l’aperçu
          </button>
        </div>
      </section>

      {preview && !current && (
        <section className="space-y-3" aria-label="Aperçu de l’import">
          <p className="font-semibold">Événement : {preview.event.name}</p>
          <div className="grid gap-3 sm:grid-cols-5" data-testid="preview-counts">
            <div className="rounded-xl border border-slate-200 bg-white p-4"><p className="text-2xl font-bold">{preview.counts.create}</p><p>à créer</p></div>
            <div className="rounded-xl border border-slate-200 bg-white p-4"><p className="text-2xl font-bold">{preview.counts.existing}</p><p>déjà existantes</p></div>
            <div className="rounded-xl border border-slate-200 bg-white p-4"><p className="text-2xl font-bold">{preview.counts.duplicate}</p><p>doublons</p></div>
            <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900"><p className="text-2xl font-bold">{preview.counts.reject}</p><p>rejetées</p></div>
            <div className="rounded-xl border border-slate-200 bg-white p-4"><p className="text-2xl font-bold">{preview.counts.unconfirmed}</p><p>non confirmées</p></div>
          </div>
          {preview.to_create.length > 0 && (
            <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white p-4">
              <h2 className="font-bold">Entreprises à créer</h2>
              <table className="mt-2 w-full text-left text-sm">
                <thead>
                  <tr className="text-slate-600">
                    <th className="py-1 pr-4">Ligne</th>
                    <th className="py-1 pr-4">Entreprise</th>
                    <th className="py-1">Nom d’utilisateur</th>
                  </tr>
                </thead>
                <tbody>
                  {preview.to_create.map((r) => (
                    <tr key={r.line} className="border-t border-slate-100">
                      <td className="py-1 pr-4">{r.line}</td>
                      <td className="py-1 pr-4 font-semibold">{r.name}</td>
                      <td className="py-1 font-mono">{r.username}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          <SignaledList items={preview.signaled} />
          {preview.counts.existing > 0 && <p className="text-sm text-slate-700">Les entreprises déjà existantes ne sont pas modifiées : ajoutez-les depuis « Ajouter des entreprises existantes ».</p>}
          {preview.counts.create === 0 ? (
            <p className="text-slate-700">Aucune entreprise à créer : rien à importer.</p>
          ) : (
            <div className="flex justify-end gap-2">
              <button type="button" className={button} onClick={restart}>
                Annuler
              </button>
              <button type="button" disabled={pending} onClick={() => void runConfirm()} className="min-h-10 rounded-lg bg-indigo-600 px-4 font-bold text-white hover:bg-indigo-700 disabled:opacity-60">
                Confirmer l’import ({preview.counts.create} entreprises)
              </button>
            </div>
          )}
        </section>
      )}

      {current && (
        <section className="space-y-3" aria-label="Résultat de l’import">
          {running && (
            <p role="status" className="rounded-lg border border-indigo-300 bg-indigo-50 px-4 py-3 text-indigo-900">
              Import en cours… Vous pouvez quitter cette page : le traitement continue.
            </p>
          )}
          {current.status === 'termine' && (
            <p role="status" className="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-green-800">
              Import terminé pour {current.event.name}
              {current.event.deleted ? ' (événement supprimé)' : ''} : {current.counts.create} entreprise(s) créée(s), {current.counts.existing} déjà existante(s), {current.counts.duplicate} doublon(s), {current.counts.reject} rejetée(s).
            </p>
          )}
          {current.status === 'echec' && (
            <div role="alert" className="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-red-800">
              <p>{current.error ?? 'L’import a échoué.'}</p>
              <button type="button" className={`${button} mt-2`} onClick={restart}>
                Recommencer
              </button>
            </div>
          )}
          <SignaledList items={current.signaled} />
          {current.status === 'termine' && current.has_credentials && (
            <div className="rounded-xl border border-slate-200 bg-white p-4">
              <h2 className="font-bold">Fiches de connexion</h2>
              <p className="text-slate-700">Imprimables en une fois, autant de fois que nécessaire, jusqu’à la suppression des mots de passe. Chaque affichage est tracé.</p>
              <div className="mt-3 flex flex-wrap gap-2">
                {sheets ? (
                  <>
                    <button type="button" onClick={() => window.print()} className="min-h-9 rounded-lg bg-indigo-600 px-3 text-sm font-bold text-white hover:bg-indigo-700">
                      Imprimer toutes les fiches ({sheets.sheets.length})
                    </button>
                    <button type="button" className={button} onClick={() => void printed(current.id)}>
                      J’ai imprimé les fiches
                    </button>
                    <button type="button" className={button} onClick={hideSheets}>
                      Masquer les fiches
                    </button>
                  </>
                ) : (
                  <button type="button" className={button} disabled={loadingSheets || deleting.includes(current.id)} onClick={() => void showSheets(current.id)}>
                    {loadingSheets ? 'Chargement des fiches…' : 'Afficher les fiches'}
                  </button>
                )}
                <button type="button" className={button} disabled={deleting.includes(current.id)} onClick={() => void remove(current)}>
                  {deleting.includes(current.id) ? 'Suppression des mots de passe…' : 'Supprimer les mots de passe'}
                </button>
              </div>
            </div>
          )}
          {sheets && current.has_credentials && <ImportSheets data={sheets} />}
          {!running && (
            <button type="button" className={button} onClick={restart}>
              Nouvel import
            </button>
          )}
        </section>
      )}

      <section className="rounded-2xl border border-slate-200 bg-white p-5">
        <h2 className="font-bold">Historique des imports</h2>
        {history.isError && (
          <p className="text-red-800">
            L’historique n’a pas pu être chargé.{' '}
            <button type="button" className="underline" onClick={() => void history.refetch()}>
              Réessayer
            </button>
          </p>
        )}
        <ul className="mt-2 divide-y divide-slate-100 text-sm">
          {(history.data?.data ?? []).map((item) => (
            <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <span>
                <b>{item.filename}</b> · {item.event.name}
                {item.event.deleted ? ' (supprimé)' : ''} · {STATUS_LABEL[item.status]} · {item.counts.create} {CREATED_LABEL[item.status]}
              </span>
              <button type="button" className={button} aria-label={`Voir le rapport de l’import ${item.id}`} onClick={() => void openReport(item.id)}>
                Voir le rapport
              </button>
            </li>
          ))}
          {history.data?.data.length === 0 && <li className="py-2 text-slate-600">Aucun import pour l’instant.</li>}
        </ul>
        {history.data && history.data.meta.last_page > 1 && (
          <nav aria-label="Pages de l’historique" className="mt-2 flex items-center gap-2">
            <button type="button" className={button} disabled={historyPage <= 1} onClick={() => setHistoryPage((p) => p - 1)}>
              Précédent
            </button>
            <span className="text-sm">
              Page {history.data.meta.current_page} sur {history.data.meta.last_page}
            </span>
            <button type="button" className={button} disabled={historyPage >= history.data.meta.last_page} onClick={() => setHistoryPage((p) => p + 1)}>
              Suivant
            </button>
          </nav>
        )}
      </section>
    </div>
  )
}
