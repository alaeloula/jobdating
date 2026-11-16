import { useState, type FormEvent } from 'react'
import { ApiError } from '../lib/api'
import { useReferentialMutations, useReferentialOptions, useReferentialValues, type ReferentialValue } from '../referentials/api'
import { REFERENTIALS, TAG_TYPES, type FieldConfig, type ReferentialConfig } from '../referentials/config'

type FormValues = Record<string, string>

const inputClass = 'h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm'

function errorMessage(error: unknown): string | null {
  if (!error) return null
  if (error instanceof ApiError) return error.message
  return 'Une erreur est survenue. Réessayez dans quelques instants.'
}

/** Formulaire d'ajout ou de modification d'une valeur. */
function ValueForm({
  config,
  editing,
  onDone,
}: {
  config: ReferentialConfig
  editing: ReferentialValue | null
  onDone: () => void
}) {
  const { create, update } = useReferentialMutations(config.type)
  const filieres = useReferentialOptions('filieres', true)
  const initial: FormValues = Object.fromEntries(
    config.fields.map((f) => [f.name, editing?.[f.name] == null ? (f.kind === 'tagType' ? 'point_fort' : '') : String(editing[f.name])]),
  )
  const [values, setValues] = useState<FormValues>(initial)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [banner, setBanner] = useState<string | null>(null)
  const [pendingConfirmation, setPendingConfirmation] = useState<number | null>(null)
  const saving = create.isPending || update.isPending

  const body = (confirm: boolean): Record<string, unknown> => {
    const data: Record<string, unknown> = {}
    for (const field of config.fields) {
      if (editing && field.createOnly) continue
      const raw = values[field.name] ?? ''
      data[field.name] = field.kind === 'filiere' ? (raw === '' ? null : Number(raw)) : raw === '' && !field.required ? null : raw
    }
    if (confirm) data.confirmer_changement_filiere = true
    return data
  }

  const submit = (confirm: boolean) => {
    setFieldErrors({})
    setBanner(null)
    const onError = (error: unknown) => {
      if (error instanceof ApiError && error.status === 409 && error.code === 'class_in_use') {
        setPendingConfirmation(Number(error.payload.learners_count ?? 0))
        return
      }
      if (error instanceof ApiError && Object.keys(error.errors).length > 0) {
        setFieldErrors(Object.fromEntries(Object.entries(error.errors).map(([k, v]) => [k, v[0] ?? ''])))
        return
      }
      setBanner(errorMessage(error))
    }
    const onSuccess = () => {
      setPendingConfirmation(null)
      // Après un ajout, le formulaire est vidé pour la valeur suivante.
      if (!editing) setValues(initial)
      onDone()
    }
    if (editing) update.mutate({ id: editing.id, body: body(confirm) }, { onSuccess, onError })
    else create.mutate(body(false), { onSuccess, onError })
  }

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    submit(false)
  }

  const renderField = (field: FieldConfig) => {
    const id = `${config.type}-${field.name}`
    const value = values[field.name] ?? ''
    const set = (v: string) => setValues((current) => ({ ...current, [field.name]: v }))
    const disabled = Boolean(editing && field.createOnly)
    let control
    if (field.kind === 'filiere') {
      const options = (filieres.data ?? []).filter((o) => o.active || String(o.id) === value)
      control = (
        <select id={id} value={value} onChange={(e) => set(e.target.value)} className={inputClass} required>
          <option value="">Choisir une filière</option>
          {options.map((o) => (
            <option key={o.id} value={String(o.id)}>
              {o.label}
              {o.active ? '' : ' (inactive)'}
            </option>
          ))}
        </select>
      )
    } else if (field.kind === 'tagType') {
      control = (
        <select id={id} value={value} onChange={(e) => set(e.target.value)} className={inputClass}>
          {TAG_TYPES.map((t) => (
            <option key={t.value} value={t.value}>
              {t.label}
            </option>
          ))}
        </select>
      )
    } else {
      control = (
        <input
          id={id}
          type={field.kind === 'email' ? 'email' : 'text'}
          value={value}
          onChange={(e) => set(e.target.value)}
          readOnly={disabled}
          aria-readonly={disabled || undefined}
          className={`${inputClass} ${disabled ? 'bg-slate-100 text-slate-500' : ''}`}
        />
      )
    }
    return (
      <div key={field.name} className="flex flex-col gap-1">
        <label htmlFor={id} className="text-sm font-semibold text-slate-700">
          {field.label}
          {!field.required && <span className="font-normal text-slate-500"> (facultatif)</span>}
        </label>
        {control}
        {disabled && <span className="text-xs text-slate-500">Le code d’une filière est définitif.</span>}
        {fieldErrors[field.name] && <span className="text-sm text-red-700">{fieldErrors[field.name]}</span>}
      </div>
    )
  }

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-3" noValidate>
      <h2 className="text-lg font-bold">{editing ? 'Modifier' : 'Ajouter'}</h2>
      {banner && (
        <p role="alert" className="rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">
          {banner}
        </p>
      )}
      {config.fields.map(renderField)}
      {pendingConfirmation !== null && (
        <div role="alertdialog" aria-label="Confirmer le changement de filière" className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
          <p>
            <strong>{pendingConfirmation} apprenant(s) changeront de filière.</strong> Confirmez-vous le changement ?
          </p>
          <div className="mt-2 flex gap-2">
            <button type="button" onClick={() => submit(true)} className="min-h-10 rounded-lg bg-amber-600 px-3 font-semibold text-white">
              Confirmer le changement
            </button>
            <button type="button" onClick={() => setPendingConfirmation(null)} className="min-h-10 rounded-lg border border-amber-400 bg-white px-3 font-semibold">
              Annuler
            </button>
          </div>
        </div>
      )}
      <div className="flex justify-end gap-2">
        {editing && (
          <button type="button" onClick={onDone} className="min-h-11 rounded-lg border border-slate-300 bg-white px-4 font-semibold">
            Annuler
          </button>
        )}
        <button type="submit" disabled={saving} className="min-h-11 rounded-lg bg-indigo-600 px-5 font-bold text-white disabled:opacity-60">
          {editing ? 'Enregistrer' : 'Ajouter'}
        </button>
      </div>
      <p className="text-xs text-slate-500">Une valeur utilisée n’est jamais supprimée : elle est désactivée et reste visible dans l’historique.</p>
    </form>
  )
}

/** Tableau d'un groupe de valeurs (une liste, ou les étiquettes d'un type). */
function ValuesTable({
  config,
  rows,
  caption,
  tagType,
  onEdit,
}: {
  config: ReferentialConfig
  rows: ReferentialValue[]
  caption?: string
  tagType?: string
  onEdit: (value: ReferentialValue) => void
}) {
  const { update, reorder } = useReferentialMutations(config.type)
  const filieres = useReferentialOptions('filieres', true)
  const [error, setError] = useState<string | null>(null)
  const columns = config.fields.filter((f) => f.kind !== 'tagType')

  const cell = (row: ReferentialValue, field: FieldConfig) => {
    const value = row[field.name]
    if (field.kind === 'filiere') return filieres.data?.find((o) => o.id === value)?.label ?? '—'
    return value == null || value === '' ? '—' : String(value)
  }

  const move = (index: number, delta: number) => {
    const ids = rows.map((r) => r.id)
    const target = index + delta
    if (target < 0 || target >= ids.length) return
    ;[ids[index], ids[target]] = [ids[target], ids[index]]
    setError(null)
    reorder.mutate(tagType ? { ids, type: tagType } : { ids }, { onError: (e) => setError(errorMessage(e)) })
  }

  const toggle = (row: ReferentialValue) => {
    setError(null)
    update.mutate({ id: row.id, body: { active: !row.active } }, { onError: (e) => setError(errorMessage(e)) })
  }

  return (
    <section className="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
      {caption && <h3 className="px-4 pt-3 font-bold">{caption}</h3>}
      {error && (
        <p role="alert" className="mx-4 mt-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">
          {error}
        </p>
      )}
      <table className="w-full min-w-[560px] text-left text-sm">
        <thead className="bg-slate-50 text-slate-600">
          <tr>
            {columns.map((f) => (
              <th key={f.name} scope="col" className="px-4 py-3 font-semibold">
                {f.label}
              </th>
            ))}
            <th scope="col" className="px-4 py-3 font-semibold">Statut</th>
            <th scope="col" className="px-4 py-3">
              <span className="sr-only">Actions</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 && (
            <tr>
              <td colSpan={columns.length + 2} className="px-4 py-6 text-center text-slate-500">Aucune valeur pour l’instant.</td>
            </tr>
          )}
          {rows.map((row, index) => (
            <tr key={row.id} className="border-t border-slate-100">
              {columns.map((f, i) => (
                <td key={f.name} className={`px-4 py-3 ${i === 0 ? 'font-semibold' : ''}`}>
                  {cell(row, f)}
                </td>
              ))}
              <td className={`px-4 py-3 font-medium ${row.active ? 'text-emerald-700' : 'text-slate-500'}`}>{row.active ? 'Actif' : 'Inactif'}</td>
              <td className="px-4 py-3">
                <div className="flex flex-wrap justify-end gap-2">
                  {config.orderable && (
                    <>
                      <button type="button" aria-label={`Monter ${row.display_label}`} disabled={index === 0} onClick={() => move(index, -1)} className="min-h-9 rounded-lg border border-slate-300 px-2 disabled:opacity-40">
                        ↑
                      </button>
                      <button type="button" aria-label={`Descendre ${row.display_label}`} disabled={index === rows.length - 1} onClick={() => move(index, 1)} className="min-h-9 rounded-lg border border-slate-300 px-2 disabled:opacity-40">
                        ↓
                      </button>
                    </>
                  )}
                  <button type="button" onClick={() => onEdit(row)} className="min-h-9 rounded-lg border border-slate-300 bg-white px-3 font-semibold">
                    Modifier
                  </button>
                  <button type="button" onClick={() => toggle(row)} className="min-h-9 rounded-lg border border-slate-300 bg-white px-3 font-semibold">
                    {row.active ? 'Désactiver' : 'Réactiver'}
                  </button>
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  )
}

function ReferentialTab({ config }: { config: ReferentialConfig }) {
  const values = useReferentialValues(config.type)
  const [editing, setEditing] = useState<ReferentialValue | null>(null)
  const rows = values.data ?? []

  return (
    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
      <div className="space-y-4">
        {values.isPending && <p className="text-slate-500">Chargement…</p>}
        {values.error && (
          <p role="alert" className="rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-red-800">
            {errorMessage(values.error)}
          </p>
        )}
        {values.data &&
          (config.type === 'etiquettes' ? (
            TAG_TYPES.map((t) => (
              <ValuesTable key={t.value} config={config} rows={rows.filter((r) => r.type === t.value)} caption={t.label} tagType={t.value} onEdit={setEditing} />
            ))
          ) : (
            <ValuesTable config={config} rows={rows} onEdit={setEditing} />
          ))}
      </div>
      <aside className="h-fit rounded-2xl border border-slate-200 bg-white p-5">
        <ValueForm key={editing ? `edit-${editing.id}` : 'new'} config={config} editing={editing} onDone={() => setEditing(null)} />
      </aside>
    </div>
  )
}

/** Référentiels (ADM-09) : administrateur et équipe CME ; d'après la maquette admin-12. */
export default function ReferentialsPage() {
  const [active, setActive] = useState(REFERENTIALS[0].type)
  const config = REFERENTIALS.find((r) => r.type === active) ?? REFERENTIALS[0]

  return (
    <section className="space-y-4">
      <div>
        <h1 className="text-2xl font-bold">Référentiels</h1>
        <p className="mt-1 text-sm text-slate-600">Listes de choix utilisées dans toute l’application. Gérées par l’administration et l’équipe CME.</p>
      </div>
      <div role="tablist" aria-label="Référentiels" className="flex flex-wrap gap-1 rounded-xl bg-slate-200 p-1">
        {REFERENTIALS.map((r) => (
          <button
            key={r.type}
            type="button"
            role="tab"
            aria-selected={r.type === active}
            onClick={() => setActive(r.type)}
            className={`min-h-10 rounded-lg px-4 text-sm font-semibold ${r.type === active ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
          >
            {r.tab}
          </button>
        ))}
      </div>
      <div role="tabpanel" aria-label={config.tab}>
        <ReferentialTab key={config.type} config={config} />
      </div>
    </section>
  )
}
