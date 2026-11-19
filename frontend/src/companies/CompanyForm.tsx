import { useState, type FormEvent } from 'react'
import { useReferentialOptions } from '../referentials/api'
import { ApiError } from '../lib/api'
import { createCompany, removeLogo, replaceLogo, suggestUsername, useCompanyMutations, type Company, type Credentials } from './api'

const input = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2'

type Errors = Record<string, string[]>

/**
 * Nouvelle entreprise / modification (maquette admin-04, sans événement ni emplacement : T-14).
 * À la création, le nom d'utilisateur est proposé à partir du nom et reste modifiable ; le
 * mot de passe est généré par le serveur et renvoyé une seule fois (onCreated).
 */
export default function CompanyForm({
  company,
  onCreated,
  onDone,
}: {
  company: Company | null
  onCreated: (company: Company, credentials: Credentials) => void
  onDone: (message: string) => void
}) {
  const sectors = useReferentialOptions('secteurs')
  const { update, refresh } = useCompanyMutations()
  const [values, setValues] = useState({
    name: company?.name ?? '',
    sector_id: company?.sector?.id ? String(company.sector.id) : '',
    website: company?.website ?? '',
    description: company?.description ?? '',
    contact_name: company?.contact_name ?? '',
    contact_email: company?.contact_email ?? '',
    username: '',
  })
  const [logo, setLogo] = useState<File | null>(null)
  const [errors, setErrors] = useState<Errors>({})
  const [message, setMessage] = useState<string | null>(null)
  const [pending, setPending] = useState(false)

  const set = (name: keyof typeof values, value: string) => setValues((v) => ({ ...v, [name]: value }))

  const propose = async () => {
    if (company || values.name.trim() === '' || values.username !== '') return
    try {
      set('username', await suggestUsername(values.name.trim()))
    } catch {
      /* proposition facultative */
    }
  }

  const fail = (e: unknown) => {
    if (e instanceof ApiError && Object.keys(e.errors).length > 0) setErrors(e.errors)
    else setMessage(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez dans quelques instants.')
  }

  const fields = () => ({
    name: values.name,
    sector_id: values.sector_id === '' ? null : Number(values.sector_id),
    website: values.website.trim() || null,
    description: values.description.trim() || null,
    contact_name: values.contact_name.trim() || null,
    contact_email: values.contact_email.trim() || null,
  })

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setErrors({})
    setMessage(null)
    setPending(true)
    try {
      if (!company) {
        const form = new FormData()
        Object.entries({ ...fields(), username: values.username }).forEach(([k, v]) => {
          if (v !== null && v !== '') form.append(k, String(v))
        })
        if (logo) form.append('logo', logo)
        const created = await createCompany(form)
        await refresh()
        onCreated(created.data, created.credentials)
      } else {
        await update.mutateAsync({ id: company.id, body: fields() })
        if (logo) await replaceLogo(company.id, logo)
        await refresh()
        onDone('Fiche entreprise enregistrée.')
      }
    } catch (e) {
      fail(e)
    } finally {
      setPending(false)
    }
  }

  const text = (name: keyof typeof values, label: string, type = 'text', help?: string) => (
    <div>
      <label htmlFor={`company-${name}`} className="block font-semibold text-slate-700">
        {label}
      </label>
      <input
        id={`company-${name}`}
        type={type}
        value={values[name]}
        onChange={(e) => set(name, e.target.value)}
        onBlur={name === 'name' ? () => void propose() : undefined}
        aria-invalid={errors[name] ? true : undefined}
        className={input}
      />
      {help && <p className="mt-1 text-sm text-slate-600">{help}</p>}
      {errors[name] && <p className="mt-1 text-sm text-red-700">{errors[name][0]}</p>}
    </div>
  )

  const sectorOptions = [
    ...(sectors.data ?? []),
    ...(company?.sector && !company.sector.active ? [{ id: company.sector.id, label: `${company.sector.label} (inactif)`, active: false }] : []),
  ]

  return (
    <aside className="h-fit rounded-2xl border border-slate-200 bg-white p-5">
      <h2 className="text-lg font-bold">{company ? `Modifier ${company.name}` : 'Nouvelle entreprise'}</h2>
      {!company && <p className="mt-1 text-sm text-slate-600">Les identifiants sont générés automatiquement. Aucun e-mail n’est envoyé à l’entreprise.</p>}
      {message && (
        <p role="alert" className="mt-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">
          {message}
        </p>
      )}
      <form onSubmit={(e) => void submit(e)} noValidate className="mt-4 space-y-4">
        {text('name', 'Nom de l’entreprise')}
        <div>
          <label htmlFor="company-sector_id" className="block font-semibold text-slate-700">
            Secteur
          </label>
          <select id="company-sector_id" value={values.sector_id} onChange={(e) => set('sector_id', e.target.value)} className={input}>
            <option value="">Non renseigné</option>
            {sectorOptions.map((s) => (
              <option key={s.id} value={s.id}>
                {s.label}
              </option>
            ))}
          </select>
          {errors.sector_id && <p className="mt-1 text-sm text-red-700">{errors.sector_id[0]}</p>}
        </div>
        {text('website', 'Site web', 'url')}
        <div>
          <label htmlFor="company-description" className="block font-semibold text-slate-700">
            Description
          </label>
          <textarea id="company-description" rows={3} value={values.description} onChange={(e) => set('description', e.target.value)} className={input} />
          {errors.description && <p className="mt-1 text-sm text-red-700">{errors.description[0]}</p>}
        </div>
        {text('contact_name', 'Nom du contact')}
        {text('contact_email', 'E-mail du contact', 'email', 'Pour joindre l’entreprise ; ne sert pas à la connexion.')}
        <div>
          <label htmlFor="company-logo" className="block font-semibold text-slate-700">
            Logo (PNG, JPG ou WebP, 1 Mo au plus)
          </label>
          <input id="company-logo" type="file" accept="image/png,image/jpeg,image/webp" onChange={(e) => {
              setLogo(e.target.files?.[0] ?? null)
              // Nouveau fichier choisi : l'ancien message d'erreur du logo ne le concerne plus.
              setErrors(({ logo: _old, ...rest }) => rest)
            }} className="mt-1 block w-full text-sm" />
          {errors.logo && <p className="mt-1 text-sm text-red-700">{errors.logo[0]}</p>}
          {company?.has_logo && (
            <button
              type="button"
              className="mt-2 text-sm font-semibold text-red-700 underline"
              onClick={() => void removeLogo(company.id).then(() => refresh()).then(() => onDone('Logo supprimé.')).catch(fail)}
            >
              Supprimer le logo actuel
            </button>
          )}
        </div>
        {!company && (
          <>
            <h3 className="pt-2 font-bold">Identifiants de connexion</h3>
            {text('username', 'Nom d’utilisateur', 'text', 'Proposé à partir du nom ; modifiable avant validation, définitif ensuite.')}
            <p className="rounded-lg border border-dashed border-slate-300 px-3 py-2 text-sm text-slate-600">Mot de passe : généré automatiquement à la validation.</p>
          </>
        )}
        <button type="submit" disabled={pending} className="w-full rounded-xl bg-indigo-600 px-4 py-3 font-bold text-white hover:bg-indigo-700 disabled:opacity-60">
          {pending ? 'Enregistrement…' : company ? 'Enregistrer' : 'Créer l’entreprise'}
        </button>
      </form>
    </aside>
  )
}
