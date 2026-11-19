import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiRequest } from '../lib/api'

export interface RefValue {
  id: number
  label: string
  active: boolean
}

export interface Recruiter {
  id: number
  name: string
  email: string
  phone: string
  job_title: string | null
}

export interface Company {
  id: number
  name: string
  sector: RefValue | null
  description: string | null
  website: string | null
  contact_name: string | null
  contact_email: string | null
  has_logo: boolean
  username: string | null
  status: 'actif' | 'desactive'
  credentials_version: number
  credentials_printed: boolean
  filieres: RefValue[]
  competences: RefValue[]
  recruiters: Recruiter[]
}

/** Identifiants affichés UNE seule fois (création, réinitialisation) : jamais dans le cache TanStack. */
export interface Credentials {
  username: string
  password: string
  version: number
  url: string
}

export interface CompanyFilters {
  search: string
  status: '' | 'actif' | 'desactive' | 'non_imprimee'
  page: number
}

interface Paginated {
  data: Company[]
  meta: { current_page: number; last_page: number; total: number }
}

const companiesKey = ['companies'] as const

export function useCompanies(filters: CompanyFilters) {
  const params = new URLSearchParams()
  if (filters.search.trim()) params.set('search', filters.search.trim())
  if (filters.status) params.set('status', filters.status)
  if (filters.page > 1) params.set('page', String(filters.page))
  const query = params.toString()

  return useQuery({
    queryKey: [...companiesKey, query],
    queryFn: () => apiRequest<Paginated>(`/companies${query ? `?${query}` : ''}`),
    placeholderData: keepPreviousData,
  })
}

/** Actions sans secret ; les actions qui renvoient un mot de passe passent par les fonctions directes ci-dessous. */
export function useCompanyMutations() {
  const queryClient = useQueryClient()
  const refresh = () => queryClient.invalidateQueries({ queryKey: companiesKey })

  const update = useMutation({
    mutationFn: ({ id, body }: { id: number; body: Record<string, unknown> }) => apiRequest(`/companies/${id}`, { method: 'PATCH', body }),
    onSuccess: refresh,
  })
  const profiles = useMutation({
    mutationFn: ({ id, filiere_ids, competence_ids }: { id: number; filiere_ids: number[]; competence_ids: number[] }) =>
      apiRequest(`/companies/${id}/profiles`, { method: 'PUT', body: { filiere_ids, competence_ids } }),
    onSuccess: refresh,
  })
  const action = useMutation({
    mutationFn: ({ id, name }: { id: number; name: 'deactivate' | 'reactivate' }) => apiRequest(`/companies/${id}/${name}`, { method: 'POST' }),
    onSuccess: refresh,
  })

  return { update, profiles, action, refresh }
}

export async function createCompany(form: FormData): Promise<{ data: Company; credentials: Credentials }> {
  return apiRequest('/companies', { method: 'POST', body: form })
}

export async function resetCompanyPassword(id: number): Promise<{ data: Company; credentials: Credentials }> {
  return apiRequest(`/companies/${id}/reset-password`, { method: 'POST' })
}

export async function confirmPrinted(id: number, version: number): Promise<void> {
  await apiRequest(`/companies/${id}/credentials-printed`, { method: 'POST', body: { version } })
}

export async function replaceLogo(id: number, file: File): Promise<void> {
  const form = new FormData()
  form.append('logo', file)
  await apiRequest(`/companies/${id}/logo`, { method: 'POST', body: form })
}

export async function removeLogo(id: number): Promise<void> {
  await apiRequest(`/companies/${id}/logo`, { method: 'DELETE' })
}

export async function suggestUsername(name: string): Promise<string> {
  const response = await apiRequest<{ data: { username: string } }>(`/companies/username-suggestion?name=${encodeURIComponent(name)}`)
  return response.data.username
}

export const PROFILES_WARNING_TITLE = 'À renseigner uniquement avec l’accord de l’entreprise.'
export const PROFILES_WARNING_TEXT =
  'Ne renseignez les profils recherchés que si l’entreprise vous les a communiqués elle-même. Sans information de sa part, laissez ce champ vide : les apprenants pourront toujours la rencontrer.'
