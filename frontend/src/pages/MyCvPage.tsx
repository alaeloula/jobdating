import { useQuery } from '@tanstack/react-query'
import CvViewer from '../cv/CvViewer'
import { formatDate } from '../cv/api'
import { cvFileName } from '../cv/pdf'
import { useMe } from '../auth/queries'
import { ApiError, apiRequest } from '../lib/api'

interface MyCv {
  version: string
  source: 'televerse' | 'plateforme_cme'
  added_at: string
}

/**
 * « Mon CV » (APP-03, maquette apprenant-04 ; T-21) : état et aperçu du CV validé de l'apprenant.
 * Aucune fonction de téléversement : le CV se met à jour par la plateforme CME.
 */
export default function MyCvPage() {
  const { data: me } = useMe()
  const cv = useQuery({
    queryKey: ['me', 'cv'],
    queryFn: async () => (await apiRequest<{ data: MyCv | null }>('/me/cv')).data,
  })

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold">Mon CV</h1>
      {cv.isLoading && <p className="text-slate-600">Chargement…</p>}
      {cv.isError && (
        <div role="alert" className="text-red-800">
          <p>{cv.error instanceof ApiError ? cv.error.message : 'L’état du CV n’a pas pu être chargé.'}</p>
          <button type="button" className="mt-2 min-h-9 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold" onClick={() => void cv.refetch()}>
            Réessayer
          </button>
        </div>
      )}
      {cv.isSuccess && cv.data === null && (
        <p className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 font-semibold text-amber-900">Aucun CV validé pour l’instant.</p>
      )}
      {cv.isSuccess && cv.data && (
        <>
          <p className="rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 font-semibold text-emerald-800">
            CV disponible pour les entreprises · ajouté le {formatDate(cv.data.added_at)}
          </p>
          <div className="rounded-2xl border border-slate-200 bg-white p-3">
            <CvViewer
              key={cv.data.version}
              owner="me"
              version={cv.data.version}
              title="vous"
              fileName={cvFileName(me?.first_name ?? '', me?.last_name ?? '')}
              onOutdated={() => void cv.refetch()}
            />
          </div>
        </>
      )}
      <p className="text-slate-700">Pour mettre à jour votre CV, déposez la nouvelle version sur la plateforme CME. Une fois validée, l’équipe CME l’ajoute dans JobDating.</p>
    </div>
  )
}
