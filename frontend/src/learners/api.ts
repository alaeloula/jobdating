import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiRequest } from '../lib/api'

export interface RefValue {
  id: number
  label: string
  active: boolean
}

export type LearnerStatus = 'actif' | 'desactive' | 'supprime'

/**
 * Apprenant (ADM-03). `cnie` : administrateur, CME et Direction (décision du 19/11), jamais une
 * entreprise. `phone` et `cme_learner_id` : administrateur seulement.
 */
export interface Learner {
  id: number
  first_name: string
  last_name: string
  email: string
  campus: RefValue
  promotion: RefValue
  school_class: RefValue
  filiere: RefValue
  competences: RefValue[]
  status: LearnerStatus
  cnie?: string
  phone?: string | null
  cme_learner_id?: string | null
  /** État dans l'événement courant (T-14), administrateur seulement, dans la liste. */
  event_status?: 'inscrit' | 'retire' | 'non_inscrit'
  /** État du CV (T-20) : null si aucun CV ; absent si le compte n'a pas `cv.view`. */
  cv?: CvState | null
}

/** CV courant d'un apprenant (T-20) : jamais le chemin ni l'empreinte. */
export interface CvState {
  source: 'televerse' | 'plateforme_cme'
  added_at: string
  added_by_name: string | null
  size: number
}

export interface LearnerFilters {
  search: string
  status: '' | LearnerStatus
  campus_id: string
  promotion_id: string
  class_id: string
  filiere_id: string
  competence_id: string
  /** Filtre CV (T-20) : avec ou sans CV courant. */
  cv?: '' | 'with' | 'without'
}

export const EMPTY_FILTERS: LearnerFilters = {
  search: '',
  status: 'actif',
  campus_id: '',
  promotion_id: '',
  class_id: '',
  filiere_id: '',
  competence_id: '',
}

interface Paginated {
  data: Learner[]
  meta: { current_page: number; last_page: number; total: number }
  current_event?: { id: number; name: string } | null
  /** Compteur « Sans CV (n) » (T-20) : mêmes filtres, sans le filtre CV. */
  counts?: { without_cv: number }
}

/** Filtres envoyés au serveur : valeurs vides retirées, identifiants en nombres. */
export function filtersBody(filters: LearnerFilters): Record<string, string | number> {
  const body: Record<string, string | number> = {}
  if (filters.search.trim()) body.search = filters.search.trim()
  if (filters.status) body.status = filters.status
  if (filters.cv) body.cv = filters.cv
  for (const key of ['campus_id', 'promotion_id', 'class_id', 'filiere_id', 'competence_id'] as const) {
    if (filters[key]) body[key] = Number(filters[key])
  }
  return body
}

const learnersKey = ['learners'] as const

/**
 * Liste par POST (corps JSON) : une CNIE recherchée n'apparaît jamais dans une adresse. La clé de
 * cache distingue filtres et page.
 */
export function useLearners(filters: LearnerFilters, page: number) {
  const body = filtersBody(filters)
  return useQuery({
    queryKey: [...learnersKey, 'search', body, page],
    queryFn: () => apiRequest<Paginated>('/learners/search', { method: 'POST', body: { ...body, page } }),
    placeholderData: keepPreviousData,
  })
}

/** Sélection : identifiants cochés OU filtres figés au moment du choix « tout le filtre ». */
export type Selection = { mode: 'ids'; ids: number[] } | { mode: 'filter'; filters: Record<string, string | number>; total: number }

export type BulkAction = 'deactivate' | 'add_competence'

function selectionBody(selection: Selection) {
  return selection.mode === 'ids' ? { ids: selection.ids } : { filters: selection.filters }
}

export async function countSelection(selection: Selection, action: BulkAction, competenceId?: number) {
  const response = await apiRequest<{ data: { selected: number; eligible: number } }>('/learners/selection/count', {
    method: 'POST',
    body: { ...selectionBody(selection), action, ...(competenceId ? { competence_id: competenceId } : {}) },
  })
  return response.data
}

export async function runBulk(selection: Selection, action: BulkAction, expected: number, competenceId?: number) {
  const path = action === 'deactivate' ? '/learners/bulk-deactivate' : '/learners/bulk-competences'
  const response = await apiRequest<{ data: { changed: number } }>(path, {
    method: 'POST',
    body: { ...selectionBody(selection), expected_eligible: expected, ...(competenceId ? { competence_id: competenceId } : {}) },
  })
  return response.data.changed
}

/** Fiche complète, relue au moment de la modification. */
export async function fetchLearner(id: number): Promise<Learner> {
  return (await apiRequest<{ data: Learner }>(`/learners/${id}`)).data
}

/** Le mot de passe affiché une fois ne passe jamais par le cache TanStack (requêtes ni mutations). */
export async function createLearner(body: Record<string, unknown>): Promise<{ data: Learner; password: string }> {
  return apiRequest('/learners', { method: 'POST', body })
}

export async function resetLearnerPassword(id: number): Promise<{ data: Learner; password: string }> {
  return apiRequest(`/learners/${id}/reset-password`, { method: 'POST' })
}

export function useLearnerMutations() {
  const queryClient = useQueryClient()
  const refresh = () => queryClient.invalidateQueries({ queryKey: learnersKey })

  const update = useMutation({
    mutationFn: ({ id, body }: { id: number; body: Record<string, unknown> }) => apiRequest(`/learners/${id}`, { method: 'PUT', body }),
    onSuccess: refresh,
  })
  const competences = useMutation({
    mutationFn: ({ id, competence_ids }: { id: number; competence_ids: number[] }) =>
      apiRequest(`/learners/${id}/competences`, { method: 'PUT', body: { competence_ids } }),
    onSuccess: refresh,
  })
  const action = useMutation({
    mutationFn: ({ id, name }: { id: number; name: 'deactivate' | 'reactivate' | 'restore' | 'delete' }) =>
      name === 'delete' ? apiRequest(`/learners/${id}`, { method: 'DELETE' }) : apiRequest(`/learners/${id}/${name}`, { method: 'POST' }),
    onSuccess: refresh,
  })

  return { update, competences, action, refresh }
}
