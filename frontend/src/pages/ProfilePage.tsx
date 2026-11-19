import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { useMe } from '../auth/queries'
import CompanyPresentation from '../companies/CompanyPresentation'
import { ApiError, apiRequest } from '../lib/api'
import { Alert } from '../passwords/components'
import { errorMessage, inputClass } from '../passwords/form'

const changeSchema = z
  .object({
    current_password: z.string().min(1, 'Saisissez votre mot de passe actuel.'),
    password: z.string().min(10, '10 caractères minimum.').max(64, 'Le mot de passe ne doit pas dépasser 64 caractères.'),
    password_confirmation: z.string().min(1, 'Confirmez le mot de passe.'),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: 'Les deux mots de passe ne correspondent pas.',
    path: ['password_confirmation'],
  })

type ChangeForm = z.infer<typeof changeSchema>

const FIELDS: { name: keyof ChangeForm; label: string; autoComplete: string; help?: string }[] = [
  { name: 'current_password', label: 'Mot de passe actuel', autoComplete: 'current-password' },
  { name: 'password', label: 'Nouveau mot de passe', autoComplete: 'new-password', help: '10 caractères minimum.' },
  { name: 'password_confirmation', label: 'Confirmer le nouveau mot de passe', autoComplete: 'new-password' },
]

/**
 * Mon profil (décision du 17/11/2026 : page minimale) : identité et changement du mot de passe
 * (AUTH-03), pour les 5 rôles. Les mots de passe restent dans le formulaire, jamais dans le cache.
 */
export default function ProfilePage() {
  const { data: user } = useMe()
  const [pending, setPending] = useState(false)
  const [done, setDone] = useState(false)
  const [submitError, setSubmitError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors },
  } = useForm<ChangeForm>({ resolver: zodResolver(changeSchema) })

  const onSubmit = handleSubmit(async (values) => {
    setDone(false)
    setSubmitError(null)
    setPending(true)
    try {
      await apiRequest<void>('/me/password', { method: 'PUT', body: values })
      reset()
      setDone(true)
    } catch (error) {
      if (error instanceof ApiError && Object.keys(error.errors).length > 0) {
        for (const field of ['current_password', 'password', 'password_confirmation'] as const) {
          const message = error.errors[field]?.[0]
          if (message) setError(field, { message })
        }
      } else {
        setSubmitError(errorMessage(error))
      }
    } finally {
      setPending(false)
    }
  })

  if (!user) return null

  return (
    <div className="mx-auto max-w-xl space-y-6">
      <h1 className="text-2xl font-bold">Mon profil</h1>

      <section className="rounded-2xl border border-slate-200 bg-white p-5" aria-labelledby="identity-title">
        <h2 id="identity-title" className="text-lg font-bold">
          Mon compte
        </h2>
        <dl className="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-slate-700">
          <dt className="font-semibold">Nom</dt>
          <dd>
            {user.first_name} {user.last_name}
          </dd>
          <dt className="font-semibold">Identifiant</dt>
          <dd className="break-all">{user.username ?? user.email}</dd>
          <dt className="font-semibold">Rôle</dt>
          <dd>{user.role_label}</dd>
        </dl>
      </section>

      {user.role === 'entreprise' && <CompanyPresentation username={user.username} />}

      <section className="rounded-2xl border border-slate-200 bg-white p-5" aria-labelledby="password-title">
        <h2 id="password-title" className="text-lg font-bold">
          Changer mon mot de passe
        </h2>
        {done && (
          <Alert tone="success">
            {user.role === 'entreprise'
              ? 'Mot de passe modifié. Les autres recruteurs connectés à ce compte restent connectés.'
              : 'Mot de passe modifié. Vos autres sessions ont été fermées.'}
          </Alert>
        )}
        {submitError && <Alert>{submitError}</Alert>}
        <form onSubmit={onSubmit} noValidate className="mt-4 space-y-5">
          {FIELDS.map((field) => (
            <div key={field.name}>
              <label htmlFor={field.name} className="block font-semibold text-slate-700">
                {field.label}
              </label>
              <input
                id={field.name}
                type="password"
                autoComplete={field.autoComplete}
                aria-invalid={errors[field.name] ? true : undefined}
                className={inputClass}
                {...register(field.name)}
              />
              {field.help && <p className="mt-2 text-sm text-slate-600">{field.help}</p>}
              {errors[field.name] && <p className="mt-1 text-sm text-red-700">{errors[field.name]?.message}</p>}
            </div>
          ))}
          <button
            type="submit"
            disabled={pending}
            className="w-full rounded-xl bg-indigo-600 px-4 py-3 text-lg font-bold text-white hover:bg-indigo-700 disabled:opacity-60 sm:w-auto"
          >
            {pending ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </form>
        {user.role === 'entreprise' && (
          <p className="mt-4 text-sm text-slate-600">
            En cas d’oubli, l’équipe d’organisation peut réinitialiser votre mot de passe.
          </p>
        )}
      </section>
    </div>
  )
}
