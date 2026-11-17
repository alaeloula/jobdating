import { z } from 'zod'
import { ApiError, NETWORK_ERROR_MESSAGE } from '../lib/api'

export const inputClass =
  'mt-2 block w-full rounded-xl border border-slate-300 px-4 py-3 text-lg focus:border-indigo-600 focus:ring-2 focus:ring-indigo-200 focus:outline-none'
export const buttonClass =
  'w-full rounded-xl bg-indigo-600 px-4 py-3 text-lg font-bold text-white hover:bg-indigo-700 disabled:opacity-60'

/** Politique de mot de passe (RG-AUTH-07, décision du 17/11/2026) ; le serveur fait foi. */
export const newPasswordSchema = z
  .object({
    password: z.string().min(10, '10 caractères minimum.').max(64, 'Le mot de passe ne doit pas dépasser 64 caractères.'),
    password_confirmation: z.string().min(1, 'Confirmez le mot de passe.'),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: 'Les deux mots de passe ne correspondent pas.',
    path: ['password_confirmation'],
  })

export type NewPasswordForm = z.infer<typeof newPasswordSchema>

/** Message à afficher pour une erreur d'envoi (hors erreurs par champ). */
export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) return error.message
  return NETWORK_ERROR_MESSAGE
}
