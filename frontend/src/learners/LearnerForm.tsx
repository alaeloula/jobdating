import { useState, type FormEvent } from 'react'
import { ApiError } from '../lib/api'
import { useReferentialOptions, type ReferentialOption } from '../referentials/api'
import { createLearner, useLearnerMutations, type Learner, type RefValue } from './api'

type Errors = Record<string, string[]>

const input = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2'

/**
 * Ajout et modification d'un apprenant (maquette admin-09, sans l'inscription à l'événement
 * reportée à T-14). Administrateur seulement. La valeur actuelle d'un référentiel devenu inactif
 * reste proposée « (inactive) » ; une nouvelle valeur doit être active (contrat T-02).
 */
export default function LearnerForm({
  learner,
  onCreated,
  onDone,
  onCancel,
}: {
  learner: Learner | null
  onCreated: (learner: Learner, password: string) => void
  onDone: (message: string) => void
  onCancel: () => void
}) {
  const campuses = useReferentialOptions('campus')
  const promotions = useReferentialOptions('promotions')
  const classes = useReferentialOptions('classes')
  const filieres = useReferentialOptions('filieres', true)
  const { update, refresh } = useLearnerMutations()
  const [values, setValues] = useState({
    cnie: learner?.cnie ?? '',
    first_name: learner?.first_name ?? '',
    last_name: learner?.last_name ?? '',
    email: learner?.email ?? '',
    phone: learner?.phone ?? '',
    campus_id: learner ? String(learner.campus.id) : '',
    promotion_id: learner ? String(learner.promotion.id) : '',
    class_id: learner ? String(learner.school_class.id) : '',
    cme_learner_id: learner?.cme_learner_id ?? '',
  })
  const [errors, setErrors] = useState<Errors>({})
  const [message, setMessage] = useState<string | null>(null)
  const [pending, setPending] = useState(false)

  // Le message d'erreur d'un champ disparaît dès que l'utilisateur le corrige (leçon de T-11).
  const set = (name: keyof typeof values, value: string) => {
    setValues((v) => ({ ...v, [name]: value }))
    setErrors(({ [name]: _old, ...rest }) => rest)
  }

  const options = (list: ReferentialOption[] | undefined, current: RefValue | undefined) => {
    const active = list ?? []
    return current && !active.some((o) => o.id === current.id) ? [...active, { ...current, active: false }] : active
  }

  const chosenClass = (classes.data as (ReferentialOption & { filiere_id?: number })[] | undefined)?.find((c) => String(c.id) === values.class_id)
  const filiere = chosenClass
    ? filieres.data?.find((f) => f.id === chosenClass.filiere_id)?.label
    : learner && String(learner.school_class.id) === values.class_id
      ? learner.filiere.label
      : undefined

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setErrors({})
    setMessage(null)
    setPending(true)
    const body = {
      ...values,
      phone: values.phone.trim() || null,
      cme_learner_id: values.cme_learner_id.trim() || null,
      campus_id: Number(values.campus_id) || null,
      promotion_id: Number(values.promotion_id) || null,
      class_id: Number(values.class_id) || null,
    }
    try {
      if (learner) {
        await update.mutateAsync({ id: learner.id, body })
        onDone('Fiche apprenant enregistrée.')
      } else {
        const created = await createLearner(body)
        await refresh()
        onCreated(created.data, created.password)
      }
    } catch (e) {
      if (e instanceof ApiError && Object.keys(e.errors).length > 0) setErrors(e.errors)
      else setMessage(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez dans quelques instants.')
    } finally {
      setPending(false)
    }
  }

  const field = (name: keyof typeof values, label: string, type = 'text', help?: string) => (
    <div>
      <label htmlFor={`learner-${name}`} className="block font-semibold text-slate-700">
        {label}
      </label>
      <input id={`learner-${name}`} type={type} value={values[name]} onChange={(e) => set(name, e.target.value)} className={input} />
      {help && <p className="mt-1 text-sm text-slate-600">{help}</p>}
      {errors[name] && <p className="mt-1 text-sm text-red-700">{errors[name][0]}</p>}
    </div>
  )

  const select = (name: 'campus_id' | 'promotion_id' | 'class_id', label: string, list: ReferentialOption[]) => (
    <div>
      <label htmlFor={`learner-${name}`} className="block font-semibold text-slate-700">
        {label}
      </label>
      <select id={`learner-${name}`} value={values[name]} onChange={(e) => set(name, e.target.value)} className={input}>
        <option value="">Choisir…</option>
        {list.map((o) => (
          <option key={o.id} value={o.id}>
            {o.label}
            {o.active ? '' : ' (inactive)'}
          </option>
        ))}
      </select>
      {errors[name] && <p className="mt-1 text-sm text-red-700">{errors[name][0]}</p>}
    </div>
  )

  return (
    <aside className="h-fit space-y-4 rounded-2xl border border-slate-200 bg-white p-5">
      <h2 className="text-lg font-bold">{learner ? `Modifier ${learner.first_name} ${learner.last_name}` : 'Ajouter un apprenant'}</h2>
      {!learner && (
        <div className="rounded-lg border border-dashed border-slate-300 p-3 text-sm text-slate-700">
          <p className="font-semibold">Ce qui se passe à la création</p>
          <ol className="mt-1 list-decimal pl-5">
            <li>Un mot de passe unique est généré.</li>
            <li>Il est envoyé à l’e-mail professionnel de l’apprenant et affiché une fois ici.</li>
            <li>L’apprenant peut le changer depuis « Mon profil ».</li>
          </ol>
          <p className="mt-1 text-slate-600">Une CNIE déjà enregistrée est refusée : modifiez plutôt la fiche existante.</p>
        </div>
      )}
      {message && (
        <p role="alert" className="rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">
          {message}
        </p>
      )}
      <form className="space-y-3" onSubmit={(e) => void submit(e)} noValidate>
        <h3 className="font-bold">Identité</h3>
        {field('cnie', 'CNIE (ou passeport / carte de séjour)', 'text', 'Obligatoire et unique. Jamais visible par les entreprises.')}
        {field('email', 'E-mail professionnel', 'email', 'Identifiant de connexion ; le mot de passe y est envoyé.')}
        {field('first_name', 'Prénom')}
        {field('last_name', 'Nom')}
        {field('phone', 'Téléphone', 'tel', 'Non visible par les entreprises.')}
        <h3 className="pt-2 font-bold">Scolarité</h3>
        {select('campus_id', 'Campus', options(campuses.data, learner?.campus))}
        {select('promotion_id', 'Promotion', options(promotions.data, learner?.promotion))}
        {select('class_id', 'Classe', options(classes.data, learner?.school_class))}
        {filiere && <p className="text-sm text-slate-600">Filière déduite : {filiere}</p>}
        {field('cme_learner_id', 'Identifiant CME (facultatif)')}
        <div className="flex justify-end gap-2 pt-2">
          <button type="button" onClick={onCancel} className="min-h-10 rounded-lg border border-slate-300 bg-white px-4 font-semibold hover:bg-slate-50">
            Annuler
          </button>
          <button type="submit" disabled={pending} className="min-h-10 rounded-lg bg-indigo-600 px-4 font-bold text-white hover:bg-indigo-700 disabled:opacity-60">
            {learner ? 'Enregistrer' : 'Créer l’apprenant'}
          </button>
        </div>
      </form>
    </aside>
  )
}
