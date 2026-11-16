import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { useLocation } from 'react-router'
import { z } from 'zod'
import { useLogin } from '../auth/queries'
import { SESSION_EXPIRED_MESSAGE, type LoginLocationState } from '../auth/types'
import campusPhoto from '../assets/campus-youcode.jpg'
import { ApiError } from '../lib/api'

const loginSchema = z.object({
  identifier: z.string().trim().min(1, "Saisissez votre e-mail ou votre nom d'utilisateur."),
  password: z.string().min(1, 'Saisissez votre mot de passe.'),
})

type LoginForm = z.infer<typeof loginSchema>

/** Écran de connexion (AUTH-01), d'après la maquette 00-connexion. */
export default function LoginPage() {
  const location = useLocation()
  const state = location.state as LoginLocationState | null
  const login = useLogin()

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<LoginForm>({ resolver: zodResolver(loginSchema) })

  const onSubmit = handleSubmit((values) => {
    // En cas de succès, la garde GuestOnly dirige vers la page demandée ou l'accueil du rôle.
    login.mutate(values, {
      onError: (error) => {
        if (error instanceof ApiError) {
          for (const field of ['identifier', 'password'] as const) {
            const message = error.errors[field]?.[0]
            if (message) setError(field, { message })
          }
        }
      },
    })
  })

  // Les erreurs par champ s'affichent sous les champs ; les autres (identifiants incorrects,
  // compte désactivé, trop de tentatives, réseau) dans le bandeau.
  const serverError =
    login.error instanceof ApiError
      ? Object.keys(login.error.errors).length > 0
        ? null
        : login.error.message
      : login.error
        ? 'Une erreur est survenue. Réessayez dans quelques instants.'
        : null
  const showExpired = state?.expired === true && !login.isError

  return (
    <div className="min-h-screen bg-slate-100 text-slate-900 lg:grid lg:grid-cols-2">
      {/*
        Photo du campus, sur grand écran seulement (choix du responsable technique, 14/11/2026).
        En image de fond d'un bloc masqué : elle n'est pas téléchargée sur mobile.
      */}
      <div
        role="img"
        aria-label="Campus YouCode"
        className="hidden bg-cover bg-center lg:block"
        style={{ backgroundImage: `url(${campusPhoto})` }}
      />

      <div className="mx-auto max-w-md px-4 py-10 lg:flex lg:w-full lg:flex-col lg:justify-center lg:py-16">
        <h1 className="text-4xl font-bold text-indigo-800">JobDating</h1>
        <p className="mt-2 text-lg text-slate-600">YouCode</p>

        <div className="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <h2 className="text-2xl font-bold">Connexion</h2>

          {showExpired && (
            <p role="status" className="mt-4 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
              {SESSION_EXPIRED_MESSAGE}
            </p>
          )}

          {serverError && (
            <p role="alert" className="mt-4 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">
              {serverError}
            </p>
          )}

          <form onSubmit={onSubmit} noValidate className="mt-5 space-y-5">
            <div>
              <label htmlFor="identifier" className="block font-semibold text-slate-700">
                E-mail ou nom d'utilisateur
              </label>
              <input
                id="identifier"
                type="text"
                autoComplete="username"
                autoCapitalize="none"
                spellCheck={false}
                aria-invalid={errors.identifier ? true : undefined}
                aria-describedby="identifier-help"
                className="mt-2 block w-full rounded-xl border border-slate-300 px-4 py-3 text-lg focus:border-indigo-600 focus:ring-2 focus:ring-indigo-200 focus:outline-none"
                {...register('identifier')}
              />
              <p id="identifier-help" className="mt-2 text-sm text-slate-600">
                Entreprises : nom d'utilisateur de votre fiche de connexion. Apprenants : e-mail professionnel.
              </p>
              {errors.identifier && <p className="mt-1 text-sm text-red-700">{errors.identifier.message}</p>}
            </div>

            <div>
              <label htmlFor="password" className="block font-semibold text-slate-700">
                Mot de passe
              </label>
              <input
                id="password"
                type="password"
                autoComplete="current-password"
                aria-invalid={errors.password ? true : undefined}
                className="mt-2 block w-full rounded-xl border border-slate-300 px-4 py-3 text-lg focus:border-indigo-600 focus:ring-2 focus:ring-indigo-200 focus:outline-none"
                {...register('password')}
              />
              {errors.password && <p className="mt-1 text-sm text-red-700">{errors.password.message}</p>}
            </div>

            <button
              type="submit"
              disabled={login.isPending}
              className="w-full rounded-xl bg-indigo-600 px-4 py-3 text-lg font-bold text-white hover:bg-indigo-700 disabled:opacity-60"
            >
              {login.isPending ? 'Connexion…' : 'Se connecter'}
            </button>
          </form>

          <p className="mt-6 text-center text-sm text-slate-600">
            Entreprise : en cas d'oubli, adressez-vous à l'équipe d'organisation, qui réinitialisera votre mot de passe.
          </p>
        </div>
      </div>
    </div>
  )
}
