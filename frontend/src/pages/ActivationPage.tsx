import { zodResolver } from '@hookform/resolvers/zod'
import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate } from 'react-router'
import { useMe } from '../auth/queries'
import { clearClientSession, logoutOtherAccount } from '../auth/session'
import type { CurrentUser } from '../auth/types'
import { ApiError, apiRequest } from '../lib/api'
import {
  Alert,
  EmailLinkForm,
  NewPasswordFields,
  OtherAccountBanner,
  PublicShell,
} from '../passwords/components'
import { buttonClass, errorMessage, newPasswordSchema, type NewPasswordForm } from '../passwords/form'
import { useLinkToken } from '../passwords/useLinkToken'

/**
 * Activer mon compte (AUTH-02) : comptes CME, Direction et administrateur. Le lien (48 h, usage
 * unique) permet de choisir son mot de passe puis ouvre la session (connexion automatique).
 */
export default function ActivationPage() {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { data: currentUser } = useMe()
  const link = useLinkToken('/activation/check')
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
      // Décision B : un autre compte connecté ici est d'abord déconnecté (le serveur le fait aussi).
      if (currentUser) await logoutOtherAccount(queryClient)
      const response = await apiRequest<{ data: CurrentUser }>('/activation', {
        method: 'POST',
        body: { token: link.token, ...values },
        silent: true,
      })
      link.clear()
      reset()
      // Nouvelle identité : aucune donnée du compte précédent ne reste en mémoire.
      clearClientSession(queryClient, response.data, false)
      navigate(response.data.home, { replace: true })
    } catch (error) {
      if (error instanceof ApiError && error.status === 410) {
        link.invalidate()
      } else if (error instanceof ApiError && Object.keys(error.errors).length > 0) {
        // Erreur récupérable : le lien reste en mémoire pour corriger et réessayer.
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
    <PublicShell title="Activer mon compte">
      {link.status === 'checking' && <p className="mt-4 text-slate-600" role="status">Vérification du lien…</p>}

      {link.status === 'error' && <Alert>Le lien n’a pas pu être vérifié. Vérifiez votre connexion et rouvrez le lien reçu par e-mail.</Alert>}

      {(link.status === 'invalid' || link.status === 'missing') && (
        <>
          <Alert tone="info">
            {link.status === 'invalid'
              ? 'Ce lien n’est plus valable. Il a expiré ou a déjà été utilisé.'
              : 'Ouvrez à nouveau le lien reçu par e-mail.'}
          </Alert>
          <p className="mt-4 text-slate-700">Recevoir un nouveau lien d’activation :</p>
          <EmailLinkForm path="/activation/resend" submitLabel="Recevoir un nouveau lien" />
        </>
      )}

      {link.status === 'valid' && (
        <>
          <p className="mt-3 text-slate-700">
            {link.firstName ? `Bonjour ${link.firstName}, choisissez` : 'Choisissez'} votre mot de passe pour activer votre compte.
          </p>
          {currentUser && <OtherAccountBanner user={currentUser} />}
          {submitError && <Alert>{submitError}</Alert>}
          <form onSubmit={onSubmit} noValidate className="mt-5 space-y-5">
            <NewPasswordFields register={register} errors={errors} />
            <button type="submit" disabled={pending} className={buttonClass}>
              {pending ? 'Activation…' : 'Activer mon compte'}
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
