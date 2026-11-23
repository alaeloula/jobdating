import { useQuery } from '@tanstack/react-query'
import { apiRequest } from '../lib/api'

export interface ImportCounts {
  create: number
  update: number
  unchanged: number
  reject: number
}

export interface Rejection {
  line: number
  reason: string
}

/** Comptes prévus pour une classe (lignes valides ; v2.2 : la classe vient de chaque ligne). */
export interface ClassCounts {
  class: string
  create: number
  update: number
  unchanged: number
}

export interface ImportPreview {
  campus: string
  promotion: string
  lines: number
  counts: ImportCounts
  classes: ClassCounts[]
  rejections: Rejection[]
  fingerprint: string
}

export type ImportStatus = 'en_attente' | 'en_cours' | 'termine' | 'echec'

export interface LearnerImport {
  id: number
  filename: string
  status: ImportStatus
  counts: ImportCounts
  classes: ClassCounts[]
  rejections: Rejection[]
  has_passwords: boolean
  passwords_deleted: boolean
  error: string | null
  author: string | null
  created_at: string
  finished_at: string | null
}

export interface ImportChoice {
  campusId: string
  promotionId: string
  file: File | null
}

function form(choice: ImportChoice, extra: Record<string, string> = {}): FormData {
  const data = new FormData()
  if (choice.file) data.append('file', choice.file)
  data.append('campus_id', choice.campusId)
  data.append('promotion_id', choice.promotionId)
  for (const [key, value] of Object.entries(extra)) data.append(key, value)
  return data
}

export async function previewImport(choice: ImportChoice): Promise<ImportPreview> {
  return (await apiRequest<{ data: ImportPreview }>('/learner-imports/preview', { method: 'POST', body: form(choice) })).data
}

/** La même clé renvoie le même import (réponse perdue) ; une nouvelle clé après tout changement. */
export async function confirmImport(choice: ImportChoice, fingerprint: string, key: string): Promise<LearnerImport> {
  return (
    await apiRequest<{ data: LearnerImport }>('/learner-imports', {
      method: 'POST',
      body: form(choice, { fingerprint, confirmation_key: key }),
    })
  ).data
}

export async function fetchImport(id: number): Promise<LearnerImport> {
  return (await apiRequest<{ data: LearnerImport }>(`/learner-imports/${id}`)).data
}

/** Historique paginé (20 par page, revue Codex T-13, constat 4). */
export function useImportHistory(page: number) {
  return useQuery({
    queryKey: ['learner-imports', page],
    queryFn: () =>
      apiRequest<{ data: LearnerImport[]; meta: { current_page: number; last_page: number } }>(`/learner-imports${page > 1 ? `?page=${page}` : ''}`),
  })
}

export async function deletePasswords(id: number): Promise<void> {
  await apiRequest(`/learner-imports/${id}/passwords`, { method: 'DELETE' })
}

/** Téléchargement du fichier des mots de passe (Excel en clair) : jamais mis en cache. */
export async function downloadPasswords(id: number): Promise<void> {
  const response = await fetch(`/api/v1/learner-imports/${id}/passwords`, {
    credentials: 'same-origin',
    headers: { Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'X-Requested-With': 'XMLHttpRequest' },
    cache: 'no-store',
  })
  if (!response.ok) throw new Error(response.status === 404 ? 'Le fichier des mots de passe n’existe plus.' : 'Le téléchargement a échoué. Réessayez.')
  const url = URL.createObjectURL(await response.blob())
  const link = document.createElement('a')
  link.href = url
  link.download = `mots-de-passe-import-${id}.xlsx`
  link.click()
  URL.revokeObjectURL(url)
}

export function newKey(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID()
    : 'xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx'.replace(/x/g, () => Math.floor(Math.random() * 16).toString(16))
}
