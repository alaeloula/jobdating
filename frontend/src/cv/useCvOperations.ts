import { useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { ApiError } from '../lib/api'
import type { Learner } from '../learners/api'
import { cvHistoryKey, removeCv, uploadCv, type CvResult } from './api'

export interface CvMessage {
  kind: 'ok' | 'error'
  text: string
}

const RESULT_TEXT: Record<CvResult, string> = {
  created: 'CV ajouté.',
  replaced: 'CV remplacé.',
  unchanged: 'CV identique au CV actuel : rien n’a changé.',
}

function errorText(e: unknown): string {
  if (e instanceof ApiError) return Object.values(e.errors ?? {}).flat()[0] ?? e.message
  return 'Une erreur est survenue. Réessayez dans quelques instants.'
}

/**
 * Envoi et retrait des CV (T-20, plan v1.2 § 7) : UNE opération à la fois par apprenant, partagée
 * entre la ligne et le panneau (double clic, envoi face à retrait). Les messages sont rangés par
 * apprenant : une réponse tardive ne s'affiche jamais sur une autre ligne. Après chaque réponse, la
 * liste, le compteur « Sans CV » et l'historique sont relus.
 */
export function useCvOperations() {
  const queryClient = useQueryClient()
  const running = useRef(new Set<number>())
  const [busy, setBusy] = useState<number[]>([])
  const [messages, setMessages] = useState<Record<number, CvMessage>>({})

  const begin = (id: number) => {
    if (running.current.has(id)) return false
    running.current.add(id)
    setBusy([...running.current])
    setMessages(({ [id]: _old, ...rest }) => rest)
    return true
  }
  const end = (id: number, message: CvMessage) => {
    running.current.delete(id)
    setBusy([...running.current])
    setMessages((m) => ({ ...m, [id]: message }))
    void queryClient.invalidateQueries({ queryKey: ['learners'] })
    void queryClient.invalidateQueries({ queryKey: cvHistoryKey(id) })
  }

  const upload = async (learner: Learner, file: File) => {
    if (!begin(learner.id)) return
    try {
      const result = await uploadCv(learner.id, file)
      end(learner.id, { kind: 'ok', text: RESULT_TEXT[result.status] })
    } catch (e) {
      end(learner.id, { kind: 'error', text: errorText(e) })
    }
  }

  const remove = async (learner: Learner) => {
    if (running.current.has(learner.id)) return
    if (!window.confirm(`Retirer définitivement le CV de ${learner.first_name} ${learner.last_name} ? Le fichier sera supprimé.`)) return
    if (!begin(learner.id)) return
    try {
      await removeCv(learner.id)
      end(learner.id, { kind: 'ok', text: 'CV retiré.' })
    } catch (e) {
      end(learner.id, { kind: 'error', text: errorText(e) })
    }
  }

  return {
    isBusy: (id: number) => busy.includes(id),
    message: (id: number): CvMessage | undefined => messages[id],
    upload,
    remove,
  }
}

export type CvOperations = ReturnType<typeof useCvOperations>
