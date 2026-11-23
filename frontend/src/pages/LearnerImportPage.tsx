import { useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { ApiError } from '../lib/api'
import { useReferentialOptions, type ReferentialOption } from '../referentials/api'
import {
  confirmImport,
  deletePasswords,
  downloadPasswords,
  fetchImport,
  newKey,
  previewImport,
  useImportHistory,
  type ClassCounts,
  type ImportChoice,
  type ImportPreview,
  type LearnerImport,
} from '../learners/importApi'

const button = 'min-h-9 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold hover:bg-slate-50 disabled:opacity-50'
const input = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2'
const POLL_MS = 3000

const STATUS_LABEL: Record<LearnerImport['status'], string> = {
  en_attente: 'En attente',
  en_cours: 'En cours',
  termine: 'Terminé',
  echec: 'Échec',
}

function message(e: unknown): string {
  if (e instanceof ApiError) return Object.values(e.errors).flat()[0] ?? e.message
  return e instanceof Error ? e.message : 'Une erreur est survenue. Réessayez dans quelques instants.'
}

/** Comptes par classe (lignes valides) : aperçu et rapport (plan T-13 v2.2). */
function ClassTable({ classes }: { classes: ClassCounts[] }) {
  if (classes.length === 0) return null
  return (
    <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white p-4">
      <h2 className="font-bold">Par classe</h2>
      <table className="mt-2 w-full text-left text-sm">
        <thead>
          <tr className="text-slate-600">
            <th className="py-1 pr-4 font-semibold">Classe</th>
            <th className="py-1 pr-4 font-semibold">À créer</th>
            <th className="py-1 pr-4 font-semibold">À mettre à jour</th>
            <th className="py-1 font-semibold">Inchangés</th>
          </tr>
        </thead>
        <tbody>
          {classes.map((c) => (
            <tr key={c.class} className="border-t border-slate-100">
              <td className="py-1 pr-4 font-semibold">{c.class}</td>
              <td className="py-1 pr-4">{c.create}</td>
              <td className="py-1 pr-4">{c.update}</td>
              <td className="py-1">{c.unchanged}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/**
 * Import des apprenants (ADM-04, T-13, maquettes admin-07 et admin-08 adaptées) : campus et
 * promotion choisis avant l'import, classe lue sur chaque ligne (v2.2) ; aperçu ; confirmation
 * traitée en file d'attente ; rapport et fichier des mots de passe (téléchargeable jusqu'à sa
 * suppression).
 */
export default function LearnerImportPage() {
  const campuses = useReferentialOptions('campus')
  const promotions = useReferentialOptions('promotions')
  const [historyPage, setHistoryPage] = useState(1)
  const history = useImportHistory(historyPage)
  const queryClient = useQueryClient()

  const [choice, setChoice] = useState<ImportChoice>({ campusId: '', promotionId: '', file: null })
  const [preview, setPreview] = useState<ImportPreview | null>(null)
  const [key, setKey] = useState<string | null>(null)
  const [current, setCurrent] = useState<LearnerImport | null>(null)
  const [error, setError] = useState<string | null>(null)
  // Erreur du SUIVI, distincte des autres : effacée à la première réponse valide (constat 8).
  const [pollError, setPollError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [pending, setPending] = useState(false)
  // Numéro de la demande d'aperçu : une réponse arrivée après un changement est ignorée.
  const request = useRef(0)
  // Génération du rapport affiché : une réponse d'une génération passée est ignorée (contre-revue T-13, N1).
  const view = useRef(0)
  const fileInput = useRef<HTMLInputElement>(null)

  /** Tout changement de choix efface l'aperçu et la clé de confirmation (plan v2.1). */
  const change = (next: Partial<ImportChoice>) => {
    request.current += 1
    // La demande en cours est abandonnée : le bouton est libéré (revue Codex T-13, constat 3).
    setPending(false)
    setChoice((c) => ({ ...c, ...next }))
    setPreview(null)
    setKey(null)
    setError(null)
    setNotice(null)
  }

  /** `reason` : message à garder affiché pendant le nouvel aperçu (ex. 409 « aperçu changé »). */
  const runPreview = async (reason: string | null = null) => {
    const id = ++request.current
    setError(reason)
    setPending(true)
    try {
      const result = await previewImport(choice)
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
    if (!preview || !key) return
    const generation = ++view.current
    setError(null)
    setPending(true)
    try {
      // En cas d'erreur réseau, « Confirmer » renvoie la MÊME clé : même import, pas de doublon.
      const created = await confirmImport(choice, preview.fingerprint, key)
      if (generation === view.current) setCurrent(created)
      else void queryClient.invalidateQueries({ queryKey: ['learner-imports'] }) // visible dans l'historique
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

  // Suivi du traitement en file d'attente.
  const currentId = current?.id
  const running = current !== null && (current.status === 'en_attente' || current.status === 'en_cours')
  useEffect(() => {
    if (!running || currentId === undefined) return
    const generation = view.current
    let sent = 0
    let newest = 0
    const timer = setInterval(() => {
      const poll = ++sent
      fetchImport(currentId)
        .then((fresh) => {
          // Autre rapport ouvert entre-temps, ou réponse plus ancienne que la dernière reçue.
          if (generation !== view.current || poll < newest) return
          newest = poll
          setPollError(null)
          setCurrent(fresh)
          if (fresh.status === 'termine' || fresh.status === 'echec') void queryClient.invalidateQueries({ queryKey: ['learner-imports'] })
        })
        .catch(() => {
          if (generation === view.current && poll >= newest) setPollError('Le suivi de l’import est momentanément indisponible ; nouvel essai dans quelques secondes.')
        })
    }, POLL_MS)
    return () => clearInterval(timer)
  }, [running, currentId, queryClient])

  const restart = () => {
    request.current += 1
    view.current += 1
    setPending(false)
    setPollError(null)
    setCurrent(null)
    setPreview(null)
    setKey(null)
    setError(null)
    setChoice((c) => ({ ...c, file: null }))
    if (fileInput.current) fileInput.current.value = ''
  }

  /** Ouvre le rapport d'un import de l'historique ; reprend son suivi s'il est encore ouvert (constat 4). */
  const openReport = async (id: number) => {
    request.current += 1
    const generation = ++view.current
    setPending(false)
    // L'ancien rapport disparaît aussitôt : ses boutons ne restent pas actifs pendant le chargement (N1).
    setCurrent(null)
    setPreview(null)
    setKey(null)
    setError(null)
    setPollError(null)
    try {
      const report = await fetchImport(id)
      if (generation === view.current) setCurrent(report)
    } catch (e) {
      if (generation === view.current) setError(message(e))
    }
  }

  const download = async (id: number) => {
    setError(null)
    try {
      await downloadPasswords(id)
    } catch (e) {
      setError(message(e))
    }
  }

  const remove = async (item: LearnerImport) => {
    if (!window.confirm('Supprimer définitivement le fichier des mots de passe de cet import ?')) return
    const generation = view.current
    setError(null) // N5 : l'erreur d'un essai précédent ne reste pas affichée
    setNotice(null)
    try {
      await deletePasswords(item.id)
      setNotice('Fichier des mots de passe supprimé.')
      if (current?.id === item.id) {
        const fresh = await fetchImport(item.id)
        if (generation === view.current) setCurrent(fresh)
      }
      await queryClient.invalidateQueries({ queryKey: ['learner-imports'] })
    } catch (e) {
      setError(message(e))
    }
  }

  const select = (label: string, value: string, options: ReferentialOption[] | undefined, key: 'campusId' | 'promotionId') => (
    <label className="block font-semibold text-slate-700">
      {label}
      <select value={value} onChange={(e) => change({ [key]: e.target.value })} className={input} disabled={running}>
        <option value="">Choisir…</option>
        {(options ?? []).map((o) => (
          <option key={o.id} value={o.id}>
            {o.label}
          </option>
        ))}
      </select>
    </label>
  )

  const ready = choice.campusId !== '' && choice.promotionId !== '' && choice.file !== null
  const toImport = preview ? preview.counts.create + preview.counts.update : 0

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-bold">Import des apprenants</h1>
        <p className="text-slate-600">Fichier « Étudiants » exporté de YouCode School Admin (CSV ou Excel) pour un campus et une promotion. La classe de chaque apprenant vient de la colonne « Classe » : elle doit exister dans les référentiels.</p>
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

      <section className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 md:grid-cols-3">
        {select('Campus', choice.campusId, campuses.data, 'campusId')}
        {select('Promotion', choice.promotionId, promotions.data, 'promotionId')}
        <label className="block font-semibold text-slate-700">
          Fichier
          <input
            ref={fileInput}
            type="file"
            accept=".csv,.xlsx"
            disabled={running}
            onChange={(e) => change({ file: e.target.files?.[0] ?? null })}
            className="mt-1 block w-full text-sm"
          />
        </label>
        <div className="md:col-span-3">
          <button type="button" className={button} disabled={!ready || pending || running} onClick={() => void runPreview()}>
            Voir l’aperçu
          </button>
        </div>
      </section>

      {preview && !current && (
        <section className="space-y-3" aria-label="Aperçu de l’import">
          <p className="font-semibold">
            {preview.lines} lignes lues pour le campus {preview.campus}, promotion {preview.promotion}
          </p>
          <div className="grid gap-3 sm:grid-cols-4" data-testid="preview-counts">
            <div className="rounded-xl border border-slate-200 bg-white p-4"><p className="text-2xl font-bold">{preview.counts.create}</p><p>à créer (mot de passe généré)</p></div>
            <div className="rounded-xl border border-slate-200 bg-white p-4"><p className="text-2xl font-bold">{preview.counts.update}</p><p>à mettre à jour (même CNIE)</p></div>
            <div className="rounded-xl border border-slate-200 bg-white p-4"><p className="text-2xl font-bold">{preview.counts.unchanged}</p><p>inchangés</p></div>
            <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900"><p className="text-2xl font-bold">{preview.counts.reject}</p><p>lignes rejetées</p></div>
          </div>
          <ClassTable classes={preview.classes} />
          {preview.rejections.length > 0 && (
            <div className="rounded-xl border border-slate-200 bg-white p-4">
              <h2 className="font-bold">Lignes rejetées</h2>
              <ul className="mt-2 space-y-1 text-sm">
                {preview.rejections.map((r) => (
                  <li key={`${r.line}-${r.reason}`}>
                    <span className="font-semibold">Ligne {r.line}</span> <span className="text-amber-800">{r.reason}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
          <p className="text-sm text-slate-600">Colonnes non enregistrées : ID, Nom complet, E-mail personnel, Ville, Date de naissance.</p>
          {toImport === 0 ? (
            <p className="text-slate-700">Aucun apprenant à créer ni à mettre à jour : rien à importer.</p>
          ) : (
            <div className="flex justify-end gap-2">
              <button type="button" className={button} onClick={restart}>
                Annuler
              </button>
              <button
                type="button"
                disabled={pending}
                onClick={() => void runConfirm()}
                className="min-h-10 rounded-lg bg-indigo-600 px-4 font-bold text-white hover:bg-indigo-700 disabled:opacity-60"
              >
                Confirmer l’import ({toImport} apprenants)
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
              Import terminé : {current.counts.create} comptes créés, {current.counts.update} mis à jour, {current.counts.unchanged} inchangés, {current.counts.reject} lignes rejetées.
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
          <ClassTable classes={current.classes} />
          {current.rejections.length > 0 && (
            <div className="rounded-xl border border-slate-200 bg-white p-4">
              <h2 className="font-bold">Lignes rejetées ({current.rejections.length})</h2>
              <ul className="mt-2 space-y-1 text-sm">
                {current.rejections.map((r) => (
                  <li key={`${r.line}-${r.reason}`}>
                    <span className="font-semibold">Ligne {r.line}</span> <span className="text-amber-800">{r.reason}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
          {current.status === 'termine' && current.has_passwords && (
            <div className="rounded-xl border border-slate-200 bg-white p-4">
              <h2 className="font-bold">Mots de passe des nouveaux comptes actifs</h2>
              <p className="text-slate-700">Envoyés par e-mail à l’adresse professionnelle de chaque apprenant. Le fichier peut être remis aux apprenants ou aux formateurs.</p>
              <div className="mt-3 flex flex-wrap items-center gap-3">
                <button type="button" className={button} onClick={() => void download(current.id)}>
                  Télécharger le fichier des mots de passe
                </button>
                <button type="button" className={button} onClick={() => void remove(current)}>
                  Supprimer le fichier des mots de passe
                </button>
                <span className="text-sm text-amber-800">Téléchargeable jusqu’à sa suppression · chaque téléchargement est tracé</span>
              </div>
            </div>
          )}
          {!running && (
            <button type="button" className={button} onClick={restart}>
              Nouvel import
            </button>
          )}
        </section>
      )}

      <section className="rounded-2xl border border-slate-200 bg-white p-5">
        <h2 className="font-bold">Historique des imports</h2>
        {history.isError && <p className="text-red-800">L’historique n’a pas pu être chargé.</p>}
        <ul className="mt-2 divide-y divide-slate-100 text-sm">
          {(history.data?.data ?? []).map((item) => (
            <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <span>
                <b>{item.filename}</b> · {STATUS_LABEL[item.status]} · {item.counts.create} créés, {item.counts.update} mis à jour, {item.counts.reject} rejetés
              </span>
              <span className="flex gap-2">
                <button type="button" className={button} aria-label={`Voir le rapport de l’import ${item.id}`} onClick={() => void openReport(item.id)}>
                  Voir le rapport
                </button>
              {item.has_passwords && (
                <span className="flex gap-2">
                  <button type="button" className={button} onClick={() => void download(item.id)}>
                    Mots de passe
                  </button>
                  <button type="button" className={button} onClick={() => void remove(item)}>
                    Supprimer
                  </button>
                </span>
              )}
              </span>
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
