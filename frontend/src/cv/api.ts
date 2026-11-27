import { useQuery } from '@tanstack/react-query'
import { apiRequest } from '../lib/api'
import type { CvState } from '../learners/api'

export const CV_UPLOAD = 'cv.upload'
export const CV_HISTORY = 'cv.history'

export type CvResult = 'created' | 'replaced' | 'unchanged'

export interface CvHistoryEntry {
  id: number
  action: 'televerse' | 'remplace' | 'retire' | 'importe' | 'inchange' | 'echec'
  source: CvState['source']
  author: string | null
  size: number | null
  reason: string | null
  created_at: string
}

interface HistoryPage {
  data: CvHistoryEntry[]
  meta: { current_page: number; last_page: number; total: number }
}

/** Téléversement (CV-01). Le contenu du fichier ne passe jamais par le cache TanStack. */
export async function uploadCv(learnerId: number, file: File): Promise<{ data: CvState; status: CvResult }> {
  const form = new FormData()
  form.append('file', file)
  return apiRequest(`/learners/${learnerId}/cv`, { method: 'POST', body: form })
}

/** Retrait (DECISIONS 26/11). */
export async function removeCv(learnerId: number): Promise<void> {
  await apiRequest(`/learners/${learnerId}/cv`, { method: 'DELETE' })
}

export const cvHistoryKey = (learnerId: number) => ['cv-history', learnerId] as const

/** Historique des ajouts (CV-04), du plus récent au plus ancien. */
export function useCvHistory(learnerId: number, page: number) {
  return useQuery({
    queryKey: [...cvHistoryKey(learnerId), page],
    queryFn: () => apiRequest<HistoryPage>(`/learners/${learnerId}/cv/history?page=${page}`),
  })
}

const DATE = new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'Africa/Casablanca' })

export function formatDate(iso: string): string {
  return DATE.format(new Date(iso))
}

/** Libellé de l'état du CV (maquette cme-01). */
export function cvLabel(cv: CvState | null | undefined): string {
  if (!cv) return 'Aucun CV'
  return cv.source === 'plateforme_cme' ? `Plateforme CME · ${formatDate(cv.added_at)}` : `Téléversé le ${formatDate(cv.added_at)}`
}

export const HISTORY_LABEL: Record<CvHistoryEntry['action'], string> = {
  televerse: 'Téléversé',
  remplace: 'Remplacé',
  retire: 'Retiré',
  importe: 'Importé (plateforme CME)',
  inchange: 'Inchangé (plateforme CME)',
  echec: 'Échec de l’import',
}
