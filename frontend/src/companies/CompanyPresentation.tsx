import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, apiRequest } from '../lib/api'

interface MyCompany {
  name: string
  description: string | null
  website: string | null
}

const input = 'mt-2 block w-full rounded-xl border border-slate-300 px-4 py-3'
const key = ['me', 'company'] as const

/** « Présentation » de l'entreprise connectée (ENT-08, maquette entreprise-08) : description et site web. */
export default function CompanyPresentation({ username }: { username: string | null }) {
  const company = useQuery({ queryKey: key, queryFn: async () => (await apiRequest<{ data: MyCompany }>('/me/company')).data })

  if (company.isPending) return <p className="text-slate-500">Chargement…</p>
  if (!company.data) return null

  return <PresentationForm key={company.data.name} company={company.data} username={username} />
}

function PresentationForm({ company, username }: { company: MyCompany; username: string | null }) {
  const queryClient = useQueryClient()
  const [values, setValues] = useState({ description: company.description ?? '', website: company.website ?? '' })
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const save = useMutation({
    mutationFn: (body: { description: string | null; website: string | null }) => apiRequest('/me/company', { method: 'PUT', body }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: key }),
  })

  const submit = (event: FormEvent) => {
    event.preventDefault()
    setErrors({})
    save.mutate(
      { description: values.description.trim() || null, website: values.website.trim() || null },
      { onError: (e) => setErrors(e instanceof ApiError ? e.errors : {}) },
    )
  }

  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-5" aria-labelledby="presentation-title">
      <h2 id="presentation-title" className="text-lg font-bold">
        Présentation
      </h2>
      {save.isSuccess && (
        <p role="status" className="mt-3 rounded-lg border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-800">
          Présentation enregistrée.
        </p>
      )}
      <form onSubmit={submit} noValidate className="mt-4 space-y-4">
        <div>
          <label htmlFor="presentation-description" className="block font-semibold text-slate-700">
            Description
          </label>
          <textarea id="presentation-description" rows={4} value={values.description} onChange={(e) => setValues({ ...values, description: e.target.value })} className={input} />
          {errors.description && <p className="mt-1 text-sm text-red-700">{errors.description[0]}</p>}
        </div>
        <div>
          <label htmlFor="presentation-website" className="block font-semibold text-slate-700">
            Site web
          </label>
          <input id="presentation-website" type="url" value={values.website} onChange={(e) => setValues({ ...values, website: e.target.value })} className={input} />
          {errors.website && <p className="mt-1 text-sm text-red-700">{errors.website[0]}</p>}
        </div>
        <p className="text-sm text-slate-600">
          Nom d’utilisateur : <span className="font-semibold">{username}</span>
        </p>
        <button type="submit" disabled={save.isPending} className="w-full rounded-xl bg-indigo-600 px-4 py-3 font-bold text-white hover:bg-indigo-700 disabled:opacity-60 sm:w-auto">
          {save.isPending ? 'Enregistrement…' : 'Enregistrer'}
        </button>
      </form>
    </section>
  )
}
