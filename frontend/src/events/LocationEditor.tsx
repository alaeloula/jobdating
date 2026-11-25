import { useEffect, useRef, useState, type FormEvent } from 'react'
import { ApiError } from '../lib/api'
import { errorMessage, saveLocation, useRefreshEvents } from './api'

const input = 'min-h-9 w-24 rounded-lg border border-slate-300 px-2'

/**
 * Saisie de l'étage et de la table (RG-PAR-02 : facultatifs). La dernière modification enregistrée
 * est conservée (décision 9). Une erreur laisse la saisie en place pour un nouvel essai.
 */
export default function LocationEditor({
  eventId,
  companyId,
  companyName,
  floor,
  table,
  disabled = false,
  onSaved,
}: {
  eventId: number
  companyId: number
  companyName: string
  floor: string | null
  table: string | null
  disabled?: boolean
  onSaved: (message: string) => void
}) {
  const refresh = useRefreshEvents()
  const [values, setValues] = useState({ floor: floor ?? '', table: table ?? '' })
  const [pending, setPending] = useState(false)
  const [error, setError] = useState<string | null>(null)
  // Réponse arrivée après un changement d'événement : elle ne concerne plus l'écran affiché.
  const mounted = useRef(true)
  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
    }
  }, [])

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    if (pending) return
    setPending(true)
    setError(null)
    try {
      await saveLocation(eventId, companyId, values.floor.trim() || null, values.table.trim() || null)
      if (mounted.current) onSaved(`Emplacement de ${companyName} enregistré.`)
      await refresh()
    } catch (e) {
      const fields = e instanceof ApiError ? Object.values(e.errors)[0]?.[0] : undefined
      setError(fields ?? errorMessage(e))
    } finally {
      setPending(false)
    }
  }

  return (
    <form onSubmit={(e) => void submit(e)} noValidate className="flex flex-wrap items-center gap-2">
      <input
        aria-label={`Étage de ${companyName}`}
        placeholder="Étage"
        value={values.floor}
        disabled={disabled}
        maxLength={50}
        onChange={(e) => setValues((v) => ({ ...v, floor: e.target.value }))}
        className={input}
      />
      <input
        aria-label={`Table de ${companyName}`}
        placeholder="Table"
        value={values.table}
        disabled={disabled}
        maxLength={50}
        onChange={(e) => setValues((v) => ({ ...v, table: e.target.value }))}
        className={input}
      />
      <button
        type="submit"
        aria-label={`Enregistrer l’emplacement de ${companyName}`}
        disabled={disabled || pending}
        className="min-h-9 rounded-lg bg-teal-700 px-3 text-sm font-bold text-white hover:bg-teal-800 disabled:opacity-50"
      >
        {pending ? 'Enregistrement…' : 'Enregistrer'}
      </button>
      {error && (
        <p role="alert" className="w-full text-sm text-red-700">
          {error}
        </p>
      )}
    </form>
  )
}
