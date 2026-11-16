export type RoleName = 'administrateur' | 'cme' | 'direction' | 'entreprise' | 'apprenant'

/** Utilisateur connecté, tel que renvoyé par GET /api/v1/me. */
export interface CurrentUser {
  id: number
  first_name: string
  last_name: string
  email: string | null
  username: string | null
  role: RoleName
  role_label: string
  permissions: string[]
  /** Page d'accueil du rôle, fixée par le serveur. */
  home: string
}

export const FORBIDDEN_MESSAGE = "Vous n'avez pas accès à cette page."
export const SESSION_EXPIRED_MESSAGE = 'Votre session a expiré. Veuillez vous reconnecter.'

/** État de navigation transmis à l'écran de connexion. */
export interface LoginLocationState {
  from?: { pathname: string; search?: string }
  expired?: boolean
}

/** État de navigation portant un message à afficher (par exemple après un refus d'accès). */
export interface FlashLocationState {
  flash?: string
}
