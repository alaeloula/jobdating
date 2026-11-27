import { useRef } from 'react'
import type { Learner } from '../learners/api'
import type { CvOperations } from './useCvOperations'

const button = 'min-h-9 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold hover:bg-slate-50 disabled:opacity-50'
const primary = 'min-h-9 rounded-lg border border-teal-700 bg-white px-3 text-sm font-semibold text-teal-800 hover:bg-teal-50 disabled:opacity-50'

interface Props {
  learner: Learner
  ops: CvOperations
  canUpload: boolean
  onHistory?: () => void
}

/** Actions sur le CV d'un apprenant (maquette cme-01) : téléverser ou remplacer, retirer, historique. */
export default function CvActions({ learner, ops, canUpload, onHistory }: Props) {
  const input = useRef<HTMLInputElement>(null)
  const busy = ops.isBusy(learner.id)
  const message = ops.message(learner.id)
  const name = `${learner.first_name} ${learner.last_name}`

  return (
    <div className="space-y-1">
      <div className="flex flex-wrap justify-end gap-2">
        {canUpload && (
          <>
            <input
              ref={input}
              type="file"
              accept="application/pdf,.pdf"
              className="hidden"
              aria-label={`Fichier PDF du CV de ${name}`}
              onChange={(e) => {
                const file = e.target.files?.[0]
                // Valeur vidée : le même fichier peut être choisi à nouveau après une erreur.
                e.target.value = ''
                if (file) void ops.upload(learner, file)
              }}
            />
            <button type="button" className={primary} disabled={busy} onClick={() => input.current?.click()}>
              {busy ? 'Envoi…' : learner.cv ? 'Remplacer (PDF)' : 'Téléverser (PDF)'}
            </button>
            {learner.cv && (
              <button type="button" className={button} disabled={busy} onClick={() => void ops.remove(learner)}>
                Retirer
              </button>
            )}
          </>
        )}
        {onHistory && (
          <button type="button" className={button} onClick={onHistory}>
            Historique
          </button>
        )}
      </div>
      {message && (
        <p role={message.kind === 'error' ? 'alert' : 'status'} className={`text-right text-sm ${message.kind === 'error' ? 'text-red-800' : 'text-green-800'}`}>
          {message.text}
        </p>
      )}
    </div>
  )
}
