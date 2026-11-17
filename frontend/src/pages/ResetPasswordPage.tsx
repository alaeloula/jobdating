import { zodResolver } from '@hookform/resolvers/zod'
import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate } from 'react-router'
import { useMe } from '../auth/queries'
import { clearClientSession, logoutOtherAccount } from '../auth/session'
import type { LoginLocationState } from '../auth/types'
import { ApiError, apiRequest } from '../lib/api'
import {
  Alert,
  NewPasswordFields,
  OtherAccountBanner,
  PublicShell,
} from '../passwords/components'
import { buttonClass, errorMessage, newPasswordSchema, type NewPasswordForm } from '../passwords/form'
import { useLinkToken } from '../passwords/useLinkToken'

/**
 * Nouveau mot de passe (AUTH-03, lien de 60 min à usage unique). Toutes les sessions du compte
 * sont fermées ; retour à l'écran de connexion (pas de connexion automatique).
 */
export default function ResetPasswordPage() {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { data: currentUser } = useMe()
  const link = useLinkToken('/password/reset/check')
  const [submitError, setSubmitError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)

  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors },
  } = useForm<NewPasswordForm>({ resolver: zodResolver(newPasswordSchema) })

  const onSubmit = handleSubmit(async (values) => {
    if (!link.token) return
    setSubmitError(null)
    setPending(true)
    try {
      if (currentUser) await logoutOtherAccount(queryClient)
      const response = await apiRequest<{ message: string }>('/password/reset', {
        method: 'POST',
        body: { token: link.token, ...values },
        silent: true,
      })
      link.clear()
      reset()
      // Les sessions du compte sont fermées par le serveur : plus aucune donnée en mémoire.
      clearClientSession(queryClient, null, true)
      const state: LoginLocationState = { notice: response.message }
      navigate('/login', { replace: true, state })
    } catch (error) {
      if (error instanceof ApiError && error.status === 410) {
        link.invalidate()
      } else if (error instanceof ApiError && Object.keys(error.errors).length > 0) {
        for (const field of ['password', 'password_confirmation'] as const) {
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

  return (
    <PublicShell title="Nouveau mot de passe">
      {link.status === 'checking' && <p className="mt-4 text-slate-600" role="status">Vérification du lien…</p>}

      {link.status === 'error' && <Alert>Le lien n’a pas pu être vérifié. Vérifiez votre connexion et rouvrez le lien reçu par e-mail.</Alert>}

      {(link.status === 'invalid' || link.status === 'missing') && (
        <>
          <Alert tone="info">
            {link.status === 'invalid'
              ? 'Ce lien n’est plus valable. Il a expiré ou a déjà été utilisé.'
              : 'Ouvrez à nouveau le lien reçu par e-mail.'}
          </Alert>
          <p className="mt-4 text-center">
            <Link to="/mot-de-passe-oublie" className="font-semibold text-indigo-700 hover:underline">
              Demander un nouveau lien
            </Link>
          </p>
        </>
      )}

      {link.status === 'valid' && (
        <>
          <p className="mt-3 text-slate-700">Choisissez votre nouveau mot de passe.</p>
          {currentUser && <OtherAccountBanner user={currentUser} />}
          {submitError && <Alert>{submitError}</Alert>}
          <form onSubmit={onSubmit} noValidate className="mt-5 space-y-5">
            <NewPasswordFields register={register} errors={errors} />
            <button type="submit" disabled={pending} className={buttonClass}>
              {pending ? 'Enregistrement…' : 'Enregistrer'}
            </button>
          </form>
        </>
      )}

      <p className="mt-6 text-center text-sm">
        <Link to="/login" className="font-semibold text-indigo-700 hover:underline">
          Retour à la connexion
        </Link>
      </p>
    </PublicShell>
  )
}
