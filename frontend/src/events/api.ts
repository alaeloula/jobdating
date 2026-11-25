import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiError, apiRequest } from '../lib/api'

export type EventStatus = 'a_venir' | 'en_cours' | 'termine'

export const STATUS_LABEL: Record<EventStatus, string> = { a_venir: 'À venir', en_cours: 'En cours', termine: 'Terminé' }

export interface EventItem {
  id: number
  name: string
  school_year: string
  full_name: string
  date: string
  created_on: string
  location: string
  latitude: string | null
  longitude: string | null
  description: string | null
  is_current: boolean
  archived: boolean
  status: EventStatus
  passages: number
  date_locked: boolean
}

export interface EventReport {
  enrolled: number
  already: number
  ignored_deactivated: number
  ignored_removed: number
}

export interface EventOption {
  id: number
  name: string
  date: string
  is_current: boolean
}

/** Participation imprimée sur la fiche de connexion ou affichée à l'entreprise. */
export interface Placement {
  id: number
  name: string
  date: string
  location: string
  floor: string | null
  table_label: string | null
}

export interface Participant {
  id: number
  name: string
  sector: string | null
  status: 'actif' | 'desactive'
  floor: string | null
  table_label: string | null
  passages: number
  username?: string | null
  credentials_printed?: boolean
  can_remove?: boolean
}

export interface ParticipantsMeta {
  total: number
  without_location: number
  not_printed: number | null
}

export type ParticipantFilter = '' | 'sans_emplacement' | 'non_imprimee'

export interface LearnerDay {
  event: { id: number; name: string; date: string; location: string; description: string | null; status: EventStatus }
  companies: { id: number; name: string; sector: string | null; floor: string | null; table_label: string | null }[]
}

const eventsKey = ['events'] as const

/** Année scolaire d'une date AAAA-MM-JJ (septembre à août), pour l'aperçu du nom ; le serveur reste juge. */
export function schoolYear(date: string): string | null {
  const match = /^(\d{4})-(\d{2})-\d{2}$/.exec(date)
  if (!match) return null
  const year = Number(match[1])
  const start = Number(match[2]) >= 9 ? year : year - 1
  return `${start}/${start + 1}`
}

/** Date affichée sans passer par un fuseau : l'événement est un jour entier. */
export function formatDate(date: string): string {
  const [year, month, day] = date.split('-')
  return `${day}/${month}/${year}`
}

export function placementLabel(floor: string | null, table: string | null): string | null {
  const parts = [floor ? `Étage ${floor}` : null, table ? `table ${table}` : null].filter(Boolean)
  return parts.length > 0 ? parts.join(', ') : null
}

export function errorMessage(e: unknown): string {
  return e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez dans quelques instants.'
}

export function useEvents(enabled = true) {
  return useQuery({
    queryKey: [...eventsKey, 'list'],
    queryFn: async () => (await apiRequest<{ data: EventItem[] }>('/events')).data,
    enabled,
  })
}

export function useEventOptions(enabled = true) {
  return useQuery({
    queryKey: [...eventsKey, 'options'],
    queryFn: async () => (await apiRequest<{ data: { current_id: number | null; events: EventOption[] } }>('/event-options')).data,
    enabled,
  })
}

export function useCurrentSummary() {
  return useQuery({
    queryKey: [...eventsKey, 'summary'],
    queryFn: async () => (await apiRequest<{ data: { id: number; name: string; date: string; location: string; status: EventStatus } | null }>('/events/current/summary')).data,
  })
}

export function useParticipants(eventId: number | null, filter: ParticipantFilter) {
  return useQuery({
    queryKey: [...eventsKey, eventId, 'companies', filter],
    queryFn: () => apiRequest<{ data: Participant[]; meta: ParticipantsMeta }>(`/events/${eventId}/companies${filter ? `?filter=${filter}` : ''}`),
    enabled: eventId !== null,
    // Anciennes lignes gardées seulement pour un changement de filtre, JAMAIS d'un événement à l'autre :
    // leurs emplacements seraient enregistrés dans le mauvais événement (revue T-14, constat 1).
    placeholderData: (previous, previousQuery) => (previousQuery?.queryKey[1] === eventId ? previous : undefined),
  })
}

export function useAvailableCompanies(eventId: number | null, enabled: boolean) {
  return useQuery({
    queryKey: [...eventsKey, eventId, 'available'],
    queryFn: async () => (await apiRequest<{ data: { id: number; name: string; sector: string | null }[] }>(`/events/${eventId}/available-companies`)).data,
    enabled: enabled && eventId !== null,
  })
}

export function useMyCompanyEvent() {
  return useQuery({
    queryKey: ['me', 'company-event'],
    queryFn: async () => (await apiRequest<{ data: Placement | null }>('/me/company-event')).data,
  })
}

export function useMyLearnerEvent() {
  return useQuery({
    queryKey: ['me', 'learner-event'],
    queryFn: async () => (await apiRequest<{ data: LearnerDay | null }>('/me/learner-event')).data,
  })
}

export function useRefreshEvents() {
  const queryClient = useQueryClient()
  return () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: eventsKey }),
      queryClient.invalidateQueries({ queryKey: ['companies'] }),
      queryClient.invalidateQueries({ queryKey: ['learners'] }),
    ])
}

export async function saveEvent(id: number | null, body: Record<string, unknown>) {
  return id === null
    ? apiRequest<{ data: EventItem; reminder: string }>('/events', { method: 'POST', body })
    : apiRequest<{ data: EventItem; reminder?: string }>(`/events/${id}`, { method: 'PATCH', body })
}

export async function setCurrentEvent(id: number) {
  return apiRequest<{ data: EventItem; report: EventReport }>(`/events/${id}/current`, { method: 'POST' })
}

export async function unsetCurrentEvent() {
  await apiRequest('/events/current', { method: 'DELETE' })
}

export async function archiveEvent(id: number, archived: boolean) {
  await apiRequest(`/events/${id}/${archived ? 'archive' : 'unarchive'}`, { method: 'POST' })
}

export async function deleteEvent(id: number) {
  await apiRequest(`/events/${id}`, { method: 'DELETE' })
}

export async function addCompanies(eventId: number, companyIds: number[]) {
  return (await apiRequest<{ report: { added: number; already: number; ignored: number } }>(`/events/${eventId}/companies`, {
    method: 'POST',
    body: { company_ids: companyIds },
  })).report
}

export async function removeCompany(eventId: number, companyId: number) {
  await apiRequest(`/events/${eventId}/companies/${companyId}`, { method: 'DELETE' })
}

export async function saveLocation(eventId: number, companyId: number, floor: string | null, table: string | null) {
  await apiRequest(`/events/${eventId}/companies/${companyId}/location`, { method: 'PATCH', body: { floor, table_label: table } })
}

export async function removeLearner(eventId: number, learnerId: number) {
  await apiRequest(`/events/${eventId}/learners/${learnerId}`, { method: 'DELETE' })
}

export async function reinstateLearner(eventId: number, learnerId: number) {
  await apiRequest(`/events/${eventId}/learners/${learnerId}/reinstate`, { method: 'POST' })
}
