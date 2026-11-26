import { useQuery } from '@tanstack/react-query'
import type { Placement } from '../events/api'
import { apiRequest } from '../lib/api'

export interface CompanyImportCounts {
  create: number
  existing: number
  duplicate: number
  reject: number
  unconfirmed: number
}

export interface Signaled {
  line: number
  reason: string
  name: string | null
}

export interface CompanyImportPreview {
  event: { id: number; name: string }
  counts: CompanyImportCounts
  to_create: { line: number; name: string; username: string }[]
  signaled: Signaled[]
  fingerprint: string
}

export type CompanyImportStatus = 'en_attente' | 'en_cours' | 'termine' | 'echec'

export interface CompanyImport {
  id: number
  filename: string
  status: CompanyImportStatus
  event: { id: number | null; name: string; deleted: boolean }
  counts: CompanyImportCounts
  signaled: Signaled[]
  has_credentials: boolean
  credentials_deleted: boolean
  error: string | null
  author: string | null
  created_at: string
  finished_at: string | null
}

/** Fiche de connexion d'un import : affichée seulement, jamais mise en cache (plan T-64 v1.3, § 7). */
export interface ImportSheet {
  company_id: number
  name: string
  username: string
  password: string
  version: number
  url: string
  event: Placement | null
  notice: string | null
}

export interface ImportSheets {
  sheets: ImportSheet[]
  excluded: { name: string; reason: string }[]
}

export interface Expected {
  sheet: string
  name: string
  confirmation: string
}

export interface CompanyImportChoice {
  eventId: string
  file: File | null
}

function form(choice: CompanyImportChoice, extra: Record<string, string> = {}): FormData {
  const data = new FormData()
  if (choice.file) data.append('file', choice.file)
  data.append('event_id', choice.eventId)
  for (const [key, value] of Object.entries(extra)) data.append(key, value)
  return data
}

export function useExpected() {
  return useQuery({
    queryKey: ['company-imports', 'expected'],
    queryFn: async () => (await apiRequest<{ data: Expected }>('/company-imports/expected')).data,
    staleTime: Infinity,
  })
}

export async function previewCompanyImport(choice: CompanyImportChoice): Promise<CompanyImportPreview> {
  return (await apiRequest<{ data: CompanyImportPreview }>('/company-imports/preview', { method: 'POST', body: form(choice) })).data
}

/** La même clé renvoie le même import (réponse perdue) ; une nouvelle clé après tout changement. */
export async function confirmCompanyImport(choice: CompanyImportChoice, fingerprint: string, key: string): Promise<CompanyImport> {
  return (await apiRequest<{ data: CompanyImport }>('/company-imports', { method: 'POST', body: form(choice, { fingerprint, confirmation_key: key }) })).data
}

export async function fetchCompanyImport(id: number): Promise<CompanyImport> {
  return (await apiRequest<{ data: CompanyImport }>(`/company-imports/${id}`)).data
}

export function useCompanyImportHistory(page: number) {
  return useQuery({
    queryKey: ['company-imports', 'history', page],
    queryFn: () =>
      apiRequest<{ data: CompanyImport[]; meta: { current_page: number; last_page: number } }>(`/company-imports${page > 1 ? `?page=${page}` : ''}`),
  })
}

/** Identifiants en clair : appel direct, hors cache TanStack. */
export async function fetchImportSheets(id: number): Promise<ImportSheets> {
  return (await apiRequest<{ data: ImportSheets }>(`/company-imports/${id}/credentials`)).data
}

export async function markImportPrinted(id: number): Promise<{ marked: number; skipped: number }> {
  return (await apiRequest<{ data: { marked: number; skipped: number } }>(`/company-imports/${id}/credentials-printed`, { method: 'POST' })).data
}

export async function deleteImportCredentials(id: number): Promise<void> {
  await apiRequest(`/company-imports/${id}/credentials`, { method: 'DELETE' })
}
