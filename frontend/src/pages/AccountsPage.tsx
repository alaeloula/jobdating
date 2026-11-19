import { useState, type FormEvent } from 'react'
import {
  ROLES,
  STATUS_LABELS,
  resetPassword,
  useAccountMutations,
  useAccounts,
  type AccountFilters,
  type StaffAccount,
  type StaffRole,
} from '../accounts/api'
import { ApiError } from '../lib/api'

const input = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2'
const button = 'min-h-9 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold hover:bg-slate-50 disabled:opacity-50'

const ROLE_BADGE: Record<StaffRole, string> = {
  cme: 'bg-emerald-50 text-emerald-800',
  direction: 'bg-orange-50 text-orange-800',
  administrateur: 'bg-indigo-50 text-indigo-800',
}

const STATUS_COLOR = { actif: 'text-green-700', invitation: 'text-orange-700', desactive: 'text-slate-500', supprime: 'text-red-700' }

function message(error: unknown): string {
  return error instanceof ApiError ? error.message : 'Une erreur est survenue. Réessayez dans quelques instants.'
}

type Pending = { kind: 'deactivate' | 'delete' | 'reset'; account: StaffAccount }

const CONFIRM: Record<Pending['kind'], (a: StaffAccount) => string> = {
  deactivate: (a) => `Désactiver le compte de ${a.full_name} ? Ses sessions seront fermées immédiatement.`,
  delete: (a) => `Supprimer le compte de ${a.full_name} ? Il pourra être restauré depuis le filtre « Supprimés ».`,
  reset: (a) =>
    `Réinitialiser le mot de passe de ${a.full_name} ? Un nouveau mot de passe sera affiché une seule fois et envoyé par e-mail ; ses sessions seront fermées.`,
}

/** Comptes CME, Direction et administrateurs (ADM-01, maquette admin-10). Administrateur seul. */
export default function AccountsPage() {
  const [filters, setFilters] = useState<AccountFilters>({ search: '', role: '', status: '', page: 1 })
  const accounts = useAccounts(filters)
  const { save, action } = useAccountMutations()
  const [editing, setEditing] = useState<StaffAccount | null>(null)
  const [pending, setPending] = useState<Pending | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [revealed, setRevealed] = useState<{ name: string; password: string } | null>(null)
  const [busy, setBusy] = useState(false)

  const update = (patch: Partial<AccountFilters>) => setFilters((current) => ({ ...current, page: 1, ...patch }))

  const run = (id: number, name: 'deactivate' | 'reactivate' | 'restore' | 'resend-activation' | 'delete', done: string) => {
    setError(null)
    setNotice(null)
    action.mutate({ id, name }, { onSuccess: () => setNotice(done), onError: (e) => setError(message(e)) })
  }

  const confirm = async () => {
    if (!pending) return
    const { kind, account } = pending
    setPending(null)
    if (kind === 'deactivate') return run(account.id, 'deactivate', `Compte de ${account.full_name} désactivé.`)
    if (kind === 'delete') return run(account.id, 'delete', `Compte de ${account.full_name} supprimé.`)
    setError(null)
    setNotice(null)
    setBusy(true)
    try {
      setRevealed({ name: account.full_name, password: await resetPassword(account.id) })
    } catch (e) {
      setError(message(e))
    } finally {
      setBusy(false)
    }
  }

  const rows = accounts.data?.data ?? []
  const meta = accounts.data?.meta

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-bold">Comptes CME et Direction</h1>
        <p className="text-slate-600">Un seul rôle par compte. Le membre reçoit un lien d’activation par e-mail et choisit son mot de passe.</p>
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
          <p>{CONFIRM[pending.kind](pending.account)}</p>
          <div className="mt-3 flex gap-2">
            <button type="button" onClick={confirm} className="rounded-lg bg-amber-600 px-3 py-2 font-semibold text-white">
              Confirmer
            </button>
            <button type="button" onClick={() => setPending(null)} className={button}>
              Annuler
            </button>
          </div>
        </div>
      )}

      {revealed && (
        <div role="dialog" aria-label="Nouveau mot de passe" className="rounded-lg border border-indigo-300 bg-indigo-50 p-4">
          <p className="font-semibold">Nouveau mot de passe de {revealed.name}</p>
          <p className="mt-2 font-mono text-xl tracking-wider" data-testid="generated-password">
            {revealed.password}
          </p>
          <p className="mt-2 text-sm text-slate-700">
            Il ne sera plus affiché après fermeture. Il a aussi été envoyé par e-mail ; vous pouvez le transmettre au membre.
          </p>
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

      <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
        <section className="space-y-3">
          <div className="flex flex-wrap gap-2">
            <label className="min-w-48 flex-1">
              <span className="sr-only">Rechercher</span>
              <input
                type="search"
                placeholder="Rechercher (nom, e-mail)"
                value={filters.search}
                onChange={(e) => update({ search: e.target.value })}
                className={input}
              />
            </label>
            <label>
              <span className="sr-only">Rôle</span>
              <select aria-label="Filtrer par rôle" value={filters.role} onChange={(e) => update({ role: e.target.value as AccountFilters['role'] })} className={input}>
                <option value="">Tous les rôles</option>
                {ROLES.map((r) => (
                  <option key={r.value} value={r.value}>
                    {r.label}
                  </option>
                ))}
              </select>
            </label>
            <label>
              <span className="sr-only">Statut</span>
              <select aria-label="Filtrer par statut" value={filters.status} onChange={(e) => update({ status: e.target.value as AccountFilters['status'] })} className={input}>
                <option value="">Tous (hors supprimés)</option>
                <option value="actif">Actifs</option>
                <option value="invitation">Invitations envoyées</option>
                <option value="desactive">Désactivés</option>
                <option value="supprime">Supprimés</option>
              </select>
            </label>
          </div>

          <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table className="w-full text-left text-sm">
              <thead className="bg-slate-50 text-slate-600">
                <tr>
                  <th className="px-4 py-3">Membre</th>
                  <th className="px-4 py-3">Rôle</th>
                  <th className="px-4 py-3">Statut</th>
                  <th className="px-4 py-3">
                    <span className="sr-only">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {accounts.isPending && (
                  <tr>
                    <td colSpan={4} className="px-4 py-6 text-slate-500">
                      Chargement…
                    </td>
                  </tr>
                )}
                {accounts.isError && !accounts.data && (
                  <tr>
                    <td colSpan={4} className="px-4 py-6">
                      <p role="alert" className="text-red-800">
                        La liste des comptes n’a pas pu être chargée.
                      </p>
                      <button type="button" className={`${button} mt-2`} onClick={() => void accounts.refetch()}>
                        Réessayer
                      </button>
                    </td>
                  </tr>
                )}
                {accounts.isSuccess && rows.length === 0 && (
                  <tr>
                    <td colSpan={4} className="px-4 py-6 text-slate-500">
                      Aucun compte.
                    </td>
                  </tr>
                )}
                {rows.map((a) => (
                  <tr key={a.id} className="border-t border-slate-100 align-top">
                    <td className="px-4 py-3">
                      <p className="font-semibold">
                        {a.full_name}
                        {a.is_self && <span className="ml-1 text-xs font-normal text-slate-500">(vous)</span>}
                      </p>
                      <p className="break-all text-slate-500">{a.email}</p>
                    </td>
                    <td className="px-4 py-3">
                      <span title={a.role_label} className={`rounded-full px-2 py-1 text-xs font-semibold ${ROLE_BADGE[a.role]}`}>
                        {ROLES.find((r) => r.value === a.role)?.label ?? a.role_label}
                      </span>
                    </td>
                    <td className={`px-4 py-3 ${STATUS_COLOR[a.status]}`}>{STATUS_LABELS[a.status]}</td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap justify-end gap-2">
                        {a.status === 'supprime' ? (
                          <button type="button" className={button} onClick={() => run(a.id, 'restore', `Compte de ${a.full_name} restauré.`)}>
                            Restaurer
                          </button>
                        ) : (
                          <>
                            <button type="button" className={button} onClick={() => setEditing(a)}>
                              Modifier
                            </button>
                            {a.status === 'invitation' && (
                              <button
                                type="button"
                                className={button}
                                onClick={() => run(a.id, 'resend-activation', `Nouveau lien d’activation envoyé à ${a.email}.`)}
                              >
                                Renvoyer le lien
                              </button>
                            )}
                            {a.status === 'actif' && (
                              <button type="button" className={button} disabled={busy} onClick={() => setPending({ kind: 'reset', account: a })}>
                                Réinitialiser le mot de passe
                              </button>
                            )}
                            {a.status === 'desactive' ? (
                              <button type="button" className={button} onClick={() => run(a.id, 'reactivate', `Compte de ${a.full_name} réactivé.`)}>
                                Réactiver
                              </button>
                            ) : (
                              !a.is_self && (
                                <button type="button" className={button} onClick={() => setPending({ kind: 'deactivate', account: a })}>
                                  Désactiver
                                </button>
                              )
                            )}
                            {!a.is_self && (
                              <button type="button" className={`${button} text-red-700`} onClick={() => setPending({ kind: 'delete', account: a })}>
                                Supprimer
                              </button>
                            )}
                          </>
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
              <button type="button" className={button} disabled={meta.current_page <= 1} onClick={() => setFilters((f) => ({ ...f, page: f.page - 1 }))}>
                Précédent
              </button>
              <span>
                Page {meta.current_page} sur {meta.last_page} · {meta.total} comptes
              </span>
              <button
                type="button"
                className={button}
                disabled={meta.current_page >= meta.last_page}
                onClick={() => setFilters((f) => ({ ...f, page: f.page + 1 }))}
              >
                Suivant
              </button>
            </nav>
          )}
        </section>

        <AccountForm
          key={editing?.id ?? 'new'}
          account={editing}
          saving={save.isPending}
          onCancel={() => setEditing(null)}
          onSubmit={(body, setFieldErrors) => {
            setError(null)
            setNotice(null)
            save.mutate(
              { id: editing?.id ?? null, body },
              {
                onSuccess: () => {
                  setNotice(editing ? 'Compte modifié.' : `Compte créé. Le lien d’activation est envoyé à ${String(body.email)}.`)
                  setEditing(null)
                  setFieldErrors({}, true)
                },
                onError: (e) => {
                  if (e instanceof ApiError && Object.keys(e.errors).length > 0) setFieldErrors(e.errors, false)
                  else setError(message(e))
                },
              },
            )
          }}
        />
      </div>
    </div>
  )
}

type FieldErrors = Record<string, string[]>

function AccountForm({
  account,
  saving,
  onSubmit,
  onCancel,
}: {
  account: StaffAccount | null
  saving: boolean
  onSubmit: (body: Record<string, unknown>, setFieldErrors: (errors: FieldErrors, reset: boolean) => void) => void
  onCancel: () => void
}) {
  const initial = {
    first_name: account?.first_name ?? '',
    last_name: account?.last_name ?? '',
    email: account?.email ?? '',
    phone: account?.phone ?? '',
    role: (account?.role ?? 'cme') as StaffRole,
  }
  const [values, setValues] = useState(initial)
  const [errors, setErrors] = useState<FieldErrors>({})

  const submit = (event: FormEvent) => {
    event.preventDefault()
    onSubmit({ ...values, phone: values.phone.trim() === '' ? null : values.phone }, (next, reset) => {
      setErrors(next)
      if (reset) setValues(initial)
    })
  }

  const field = (name: 'first_name' | 'last_name' | 'email' | 'phone', label: string, type = 'text') => (
    <div>
      <label htmlFor={`account-${name}`} className="block font-semibold text-slate-700">
        {label}
      </label>
      <input
        id={`account-${name}`}
        type={type}
        value={values[name]}
        onChange={(e) => setValues((v) => ({ ...v, [name]: e.target.value }))}
        aria-invalid={errors[name] ? true : undefined}
        className={input}
      />
      {errors[name] && <p className="mt-1 text-sm text-red-700">{errors[name][0]}</p>}
    </div>
  )

  return (
    <aside className="h-fit rounded-2xl border border-slate-200 bg-white p-5">
      <h2 className="text-lg font-bold">{account ? `Modifier ${account.full_name}` : 'Ajouter un membre'}</h2>
      <form onSubmit={submit} noValidate className="mt-4 space-y-4">
        {field('first_name', 'Prénom')}
        {field('last_name', 'Nom')}
        {field('email', 'E-mail', 'email')}
        {!account && <p className="-mt-2 text-sm text-slate-600">Le lien d’activation y est envoyé.</p>}
        {field('phone', 'Téléphone (facultatif)', 'tel')}
        <fieldset>
          <legend className="font-semibold text-slate-700">Rôle</legend>
          <div className="mt-2 space-y-2">
            {ROLES.map((r) => (
              <label
                key={r.value}
                className={`block cursor-pointer rounded-lg border p-3 ${values.role === r.value ? 'border-indigo-600 bg-indigo-50' : 'border-slate-300'}`}
              >
                <input
                  type="radio"
                  name="role"
                  value={r.value}
                  checked={values.role === r.value}
                  onChange={() => setValues((v) => ({ ...v, role: r.value }))}
                  disabled={account?.is_self}
                  className="sr-only"
                />
                <span className="block font-semibold">{r.label}</span>
                <span className="block text-sm text-slate-600">{r.help}</span>
              </label>
            ))}
          </div>
          {account?.is_self && <p className="mt-1 text-sm text-slate-600">Vous ne pouvez pas changer le rôle de votre propre compte.</p>}
          {errors.role && <p className="mt-1 text-sm text-red-700">{errors.role[0]}</p>}
        </fieldset>
        <button type="submit" disabled={saving} className="w-full rounded-xl bg-indigo-600 px-4 py-3 font-bold text-white hover:bg-indigo-700 disabled:opacity-60">
          {saving ? 'Enregistrement…' : account ? 'Enregistrer' : 'Créer et envoyer le lien d’activation'}
        </button>
        {account && (
          <button type="button" onClick={onCancel} className={`${button} w-full`}>
            Annuler
          </button>
        )}
      </form>
    </aside>
  )
}
