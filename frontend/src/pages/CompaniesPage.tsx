import { useState } from 'react'
import { useMe } from '../auth/queries'
import ParticipantsPanel from '../events/ParticipantsPanel'
import { useRefreshEvents } from '../events/api'
import { ApiError } from '../lib/api'
import CompanyForm from '../companies/CompanyForm'
import CredentialsSheet from '../companies/CredentialsSheet'
import ProfilesPanel from '../companies/ProfilesPanel'
import { resetCompanyPassword, useCompanies, useCompanyMutations, type Company, type CompanyFilters, type Credentials } from '../companies/api'

const COMPANIES_MANAGE = 'companies.manage'
const PARTICIPANTS_MANAGE = 'participants.manage'
const button = 'min-h-9 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold hover:bg-slate-50 disabled:opacity-50'

/**
 * Logo en cercle (48 px) ; à défaut, ou si l'image ne se charge pas, initiales de l'entreprise
 * dans un cercle (contre-revue Codex T-11, constat 5). L'échec est mémorisé par adresse : une
 * nouvelle adresse retente le chargement.
 */
function CompanyAvatar({ company }: { company: Company }) {
  const src = `/api/v1/companies/${company.id}/logo`
  const [failedSrc, setFailedSrc] = useState<string | null>(null)
  if (company.has_logo && failedSrc !== src) {
    return (
      <img
        src={src}
        onError={() => setFailedSrc(src)}
        alt={`Logo de ${company.name}`}
        className="h-12 w-12 shrink-0 rounded-full border border-slate-200 bg-white object-contain p-1"
      />
    )
  }
  const initials = company.name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => word[0]?.toUpperCase())
    .join('')
  return (
    <span aria-hidden="true" className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-indigo-50 font-bold text-indigo-800">
      {initials}
    </span>
  )
}

/** Profils recherchés visibles directement dans la liste (filières en bleu, compétences en vert). */
function ProfileBadges({ company }: { company: Company }) {
  if (company.filieres.length === 0 && company.competences.length === 0) {
    return <p className="mt-1 text-xs text-slate-600">Aucun profil recherché</p>
  }
  return (
    <ul aria-label={`Profils recherchés par ${company.name}`} className="mt-1 flex flex-wrap gap-1">
      {company.filieres.map((f) => (
        <li key={`f-${f.id}`} className={`rounded-full px-2 py-0.5 text-xs ${f.active ? 'bg-indigo-50 text-indigo-800' : 'bg-slate-100 text-slate-600 line-through'}`}>
          {f.label}
        </li>
      ))}
      {company.competences.map((c) => (
        <li key={`c-${c.id}`} className={`rounded-full px-2 py-0.5 text-xs ${c.active ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600 line-through'}`}>
          {c.label}
        </li>
      ))}
    </ul>
  )
}

type Panel ={ kind: 'new' } | { kind: 'edit'; company: Company } | { kind: 'profiles'; company: Company } | null
type Pending = { kind: 'reset' | 'deactivate'; company: Pick<Company, 'id' | 'name'>; eventId?: number } | null

/**
 * Entreprises (ADM-02, ADM-07, maquette admin-03). Administrateur : participantes de l'événement et
 * gestion complète ; CME : consultation et profils recherchés.
 */
export default function CompaniesPage() {
  const { data: me } = useMe()
  const canManage = me?.permissions.includes(COMPANIES_MANAGE) ?? false
  const canParticipants = me?.permissions.includes(PARTICIPANTS_MANAGE) ?? false
  const [tab, setTab] = useState<'participants' | 'all'>('participants')
  const showParticipants = canParticipants && tab === 'participants'
  const refreshEvents = useRefreshEvents()
  const [filters, setFilters] = useState<CompanyFilters>({ search: '', status: '', page: 1 })
  const companies = useCompanies(filters)
  const { action, refresh } = useCompanyMutations()
  const [panel, setPanel] = useState<Panel>(null)
  const [pending, setPending] = useState<Pending>(null)
  const [sheet, setSheet] = useState<{ company: Company; credentials: Credentials } | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const say = (text: string) => {
    setError(null)
    setNotice(text)
  }
  const fail = (e: unknown) => {
    setNotice(null)
    setError(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez dans quelques instants.')
  }

  const confirm = async () => {
    if (!pending) return
    const { kind, company, eventId } = pending
    setPending(null)
    try {
      if (kind === 'reset') {
        const reset = await resetCompanyPassword(company.id, eventId)
        setPanel(null)
        setSheet({ company: reset.data, credentials: reset.credentials })
        await refresh()
        if (canParticipants) await refreshEvents()
      } else {
        await action.mutateAsync({ id: company.id, name: 'deactivate' })
        say(`${company.name} désactivée : ses recruteurs sont déconnectés.`)
      }
    } catch (e) {
      fail(e)
    }
  }

  const rows = companies.data?.data ?? []
  const meta = companies.data?.meta

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold">Entreprises</h1>
          <p className="text-slate-600">Connexion par nom d’utilisateur ; aucun e-mail envoyé.</p>
        </div>
        {canManage && (
          <button
            type="button"
            onClick={() => {
              setSheet(null)
              setPanel({ kind: 'new' })
            }}
            className="rounded-xl bg-indigo-600 px-4 py-2 font-bold text-white hover:bg-indigo-700"
          >
            Nouvelle entreprise
          </button>
        )}
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
      {pending && (
        <div role="alertdialog" aria-label="Confirmation" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-900">
          <p>
            {pending.kind === 'reset'
              ? `Réinitialiser le mot de passe de ${pending.company.name} ? Une nouvelle fiche sera à imprimer ; l’ancien mot de passe ne fonctionnera plus et ses recruteurs seront déconnectés.`
              : `Désactiver ${pending.company.name} ? Ses recruteurs ne pourront plus se connecter.`}
          </p>
          <div className="mt-3 flex gap-2">
            <button type="button" onClick={() => void confirm()} className="rounded-lg bg-amber-600 px-3 py-2 font-semibold text-white">
              Confirmer
            </button>
            <button type="button" onClick={() => setPending(null)} className={button}>
              Annuler
            </button>
          </div>
        </div>
      )}

      {canParticipants && (
        <div role="group" aria-label="Vue des entreprises" className="flex gap-1 rounded-xl bg-slate-200 p-1 sm:w-fit">
          {(
            [
              ['participants', 'Participantes à l’événement'],
              ['all', 'Toutes les entreprises'],
            ] as const
          ).map(([value, label]) => (
            <button key={value} type="button" aria-pressed={tab === value} onClick={() => setTab(value)} className={`rounded-lg px-3 py-2 text-sm font-semibold ${tab === value ? 'bg-white' : 'text-slate-700'}`}>
              {label}
            </button>
          ))}
        </div>
      )}

      {/* Colonne de droite seulement quand un formulaire ou une fiche est ouvert : la liste prend sinon toute la largeur. */}
      <div className={`grid gap-4 ${panel || sheet ? 'lg:grid-cols-[minmax(0,1fr)_24rem]' : ''}`}>
        {showParticipants && <ParticipantsPanel onReset={(company, eventId) => setPending({ kind: 'reset', company, eventId })} />}
        <section className={`space-y-3 ${showParticipants ? 'hidden' : ''}`}>
          <div className="flex flex-wrap gap-2">
            <input
              type="search"
              aria-label="Rechercher une entreprise"
              placeholder="Rechercher (nom, nom d’utilisateur)"
              value={filters.search}
              onChange={(e) => setFilters({ ...filters, search: e.target.value, page: 1 })}
              className="min-w-48 flex-1 rounded-lg border border-slate-300 px-3 py-2"
            />
            {(
              [
                ['', 'Toutes'],
                ['non_imprimee', 'Fiche non imprimée'],
                ['desactive', 'Désactivées'],
              ] as const
            ).map(([value, label]) => (
              <button
                key={value}
                type="button"
                aria-pressed={filters.status === value}
                onClick={() => setFilters({ ...filters, status: value, page: 1 })}
                className={`rounded-full border px-3 py-1 text-sm ${filters.status === value ? 'border-indigo-600 bg-indigo-50 font-semibold text-indigo-800' : 'border-slate-300'}`}
              >
                {label}
              </button>
            ))}
          </div>

          <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table className="w-full text-left text-sm">
              <thead className="bg-slate-50 text-slate-600">
                <tr>
                  <th className="px-4 py-3">Entreprise</th>
                  <th className="px-4 py-3">Nom d’utilisateur</th>
                  <th className="px-4 py-3">Fiche</th>
                  <th className="px-4 py-3">
                    <span className="sr-only">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {companies.isPending && (
                  <tr>
                    <td colSpan={4} className="px-4 py-6 text-slate-500">
                      Chargement…
                    </td>
                  </tr>
                )}
                {companies.isError && !companies.data && (
                  <tr>
                    <td colSpan={4} className="px-4 py-6">
                      <p role="alert" className="text-red-800">
                        La liste des entreprises n’a pas pu être chargée.
                      </p>
                      <button type="button" className={`${button} mt-2`} onClick={() => void companies.refetch()}>
                        Réessayer
                      </button>
                    </td>
                  </tr>
                )}
                {companies.isSuccess && rows.length === 0 && (
                  <tr>
                    <td colSpan={4} className="px-4 py-6 text-slate-500">
                      Aucune entreprise.
                    </td>
                  </tr>
                )}
                {rows.map((c) => (
                  <tr key={c.id} className="border-t border-slate-100 align-top">
                    <td className="px-4 py-3">
                      <div className="flex items-start gap-3">
                        <CompanyAvatar company={c} />
                        <div className="min-w-0">
                          <p className="font-semibold">{c.name}</p>
                          <p className="text-slate-500">{c.sector?.label ?? 'Secteur non renseigné'}</p>
                          {c.status === 'desactive' && <p className="text-xs font-semibold text-slate-500">Désactivée</p>}
                          <ProfileBadges company={c} />
                        </div>
                      </div>
                    </td>
                    <td className="px-4 py-3 font-mono">{c.username}</td>
                    <td className="px-4 py-3">{c.credentials_printed ? 'Imprimée' : <span className="text-orange-700">Non imprimée</span>}</td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap justify-end gap-2">
                        {canManage && (
                          <button type="button" className={button} onClick={() => setPanel({ kind: 'edit', company: c })}>
                            Modifier
                          </button>
                        )}
                        <button type="button" className={button} onClick={() => setPanel({ kind: 'profiles', company: c })}>
                          Profils recherchés
                        </button>
                        {canManage && c.status === 'actif' && (
                          <>
                            <button type="button" className={button} onClick={() => setPending({ kind: 'reset', company: c })}>
                              Réinitialiser le mot de passe
                            </button>
                            <button type="button" className={button} onClick={() => setPending({ kind: 'deactivate', company: c })}>
                              Désactiver
                            </button>
                          </>
                        )}
                        {canManage && c.status === 'desactive' && (
                          <button
                            type="button"
                            className={button}
                            onClick={() => action.mutate({ id: c.id, name: 'reactivate' }, { onSuccess: () => say(`${c.name} réactivée.`), onError: fail })}
                          >
                            Réactiver
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {meta && meta.last_page > 1 && (
            <nav aria-label="Pagination" className="flex items-center justify-between text-sm">
              <button type="button" className={button} disabled={meta.current_page <= 1} onClick={() => setFilters({ ...filters, page: filters.page - 1 })}>
                Précédent
              </button>
              <span>
                Page {meta.current_page} sur {meta.last_page} · {meta.total} entreprises
              </span>
              <button type="button" className={button} disabled={meta.current_page >= meta.last_page} onClick={() => setFilters({ ...filters, page: filters.page + 1 })}>
                Suivant
              </button>
            </nav>
          )}
        </section>

        <div className="space-y-4">
          {sheet && (
            <CredentialsSheet
              // Une nouvelle version des identifiants repart d'un état vierge (revue Codex T-11, constat 2).
              key={`${sheet.company.id}-${sheet.credentials.version}`}
              companyId={sheet.company.id}
              companyName={sheet.company.name}
              credentials={sheet.credentials}
              onPrinted={() => void (canParticipants ? refreshEvents() : refresh())}
              onClose={() => setSheet(null)}
            />
          )}
          {panel?.kind === 'new' && (
            <CompanyForm
              company={null}
              onCreated={(company, credentials) => {
                setPanel(null)
                setSheet({ company, credentials })
                if (canParticipants) void refreshEvents()
                say(`Entreprise ${company.name} créée. Imprimez sa fiche de connexion.`)
              }}
              onDone={say}
            />
          )}
          {panel?.kind === 'edit' && (
            <CompanyForm
              key={panel.company.id}
              company={panel.company}
              onCreated={() => undefined}
              onDone={(text) => {
                setPanel(null)
                say(text)
              }}
            />
          )}
          {panel?.kind === 'profiles' && <ProfilesPanel key={panel.company.id} company={panel.company} onClose={() => setPanel(null)} />}
        </div>
      </div>
    </div>
  )
}
