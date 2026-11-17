import { useState, type ReactNode } from 'react'
import type { FieldErrors, UseFormRegister } from 'react-hook-form'
import { ApiError, apiRequest } from '../lib/api'
import type { CurrentUser } from '../auth/types'
import { buttonClass, errorMessage, inputClass, type NewPasswordForm } from './form'

/** Page publique (activation, oubli, réinitialisation), d'après la maquette de connexion. */
export function PublicShell({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="min-h-screen bg-slate-100 text-slate-900">
      <div className="mx-auto max-w-md px-4 py-10">
        <p className="text-4xl font-bold text-indigo-800">JobDating</p>
        <p className="mt-2 text-lg text-slate-600">YouCode</p>
        <div className="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <h1 className="text-2xl font-bold">{title}</h1>
          {children}
        </div>
      </div>
    </div>
  )
}

export function Alert({ children, tone = 'error' }: { children: ReactNode; tone?: 'error' | 'info' | 'success' }) {
  const colors = {
    error: 'border-red-300 bg-red-50 text-red-800',
    info: 'border-amber-300 bg-amber-50 text-amber-900',
    success: 'border-green-300 bg-green-50 text-green-800',
  }[tone]
  return (
    <p role={tone === 'error' ? 'alert' : 'status'} className={`mt-4 rounded-lg border px-3 py-2 text-sm ${colors}`}>
      {children}
    </p>
  )
}

/** Décision B (17/11/2026) : un autre compte est connecté sur ce navigateur. */
export function OtherAccountBanner({ user }: { user: CurrentUser }) {
  return (
    <Alert tone="info">
      Vous êtes connecté en tant que {user.first_name} {user.last_name}. Pour continuer, vous serez déconnecté.
    </Alert>
  )
}

/** Nouveau mot de passe, saisi deux fois (CDC AUTH-03). */
export function NewPasswordFields({
  register,
  errors,
}: {
  register: UseFormRegister<NewPasswordForm>
  errors: FieldErrors<NewPasswordForm>
}) {
  return (
    <>
      <div>
        <label htmlFor="password" className="block font-semibold text-slate-700">
          Nouveau mot de passe
        </label>
        <input
          id="password"
          type="password"
          autoComplete="new-password"
          aria-invalid={errors.password ? true : undefined}
          aria-describedby="password-help"
          className={inputClass}
          {...register('password')}
        />
        <p id="password-help" className="mt-2 text-sm text-slate-600">
          10 caractères minimum.
        </p>
        {errors.password && <p className="mt-1 text-sm text-red-700">{errors.password.message}</p>}
      </div>
      <div>
        <label htmlFor="password_confirmation" className="block font-semibold text-slate-700">
          Confirmer le mot de passe
        </label>
        <input
          id="password_confirmation"
          type="password"
          autoComplete="new-password"
          aria-invalid={errors.password_confirmation ? true : undefined}
          className={inputClass}
          {...register('password_confirmation')}
        />
        {errors.password_confirmation && (
          <p className="mt-1 text-sm text-red-700">{errors.password_confirmation.message}</p>
        )}
      </div>
    </>
  )
}

/**
 * Demande d'un lien par e-mail (oubli ou nouveau lien d'activation). Même message quel que soit
 * le compte (RG-AUTH-09). Appel direct, hors cache TanStack.
 */
export function EmailLinkForm({ path, submitLabel }: { path: '/password/forgot' | '/activation/resend'; submitLabel: string }) {
  const [email, setEmail] = useState('')
  const [pending, setPending] = useState(false)
  const [sent, setSent] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [fieldError, setFieldError] = useState<string | null>(null)

  const submit = async (event: React.FormEvent) => {
    event.preventDefault()
    setError(null)
    setFieldError(null)
    if (email.trim() === '') {
      setFieldError('Saisissez votre adresse e-mail.')
      return
    }
    setPending(true)
    try {
      const response = await apiRequest<{ message: string }>(path, { method: 'POST', body: { email }, silent: true })
      setSent(response.message)
    } catch (caught) {
      const field = caught instanceof ApiError ? caught.errors.email?.[0] : undefined
      if (field) setFieldError(field)
      else setError(errorMessage(caught))
    } finally {
      setPending(false)
    }
  }

  if (sent) {
    return <Alert tone="success">{sent}</Alert>
  }

  return (
    <form onSubmit={submit} noValidate className="mt-5 space-y-5">
      {error && <Alert>{error}</Alert>}
      <div>
        <label htmlFor="email" className="block font-semibold text-slate-700">
          Adresse e-mail
        </label>
        <input
          id="email"
          type="email"
          autoComplete="email"
          autoCapitalize="none"
          spellCheck={false}
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          aria-invalid={fieldError ? true : undefined}
          className={inputClass}
        />
        {fieldError && <p className="mt-1 text-sm text-red-700">{fieldError}</p>}
      </div>
      <button type="submit" disabled={pending} className={buttonClass}>
        {pending ? 'Envoi…' : submitLabel}
      </button>
    </form>
  )
}
