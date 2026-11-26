import { formatDate, placementLabel } from '../events/api'
import type { ImportSheets as Sheets } from './importApi'

/**
 * Fiches de connexion d'un import, imprimées en une fois (une par page, plan T-64 v1.3, § 7). Les
 * mots de passe ne sont gardés qu'en mémoire de cet affichage.
 */
export default function ImportSheets({ data }: { data: Sheets }) {
  return (
    <div className="space-y-3">
      {data.excluded.length > 0 && (
        <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
          <p className="font-semibold">{data.excluded.length} fiche(s) exclue(s)</p>
          <ul className="mt-1 list-disc pl-5">
            {data.excluded.map((e) => (
              <li key={`${e.name}-${e.reason}`}>
                {e.name} : {e.reason}
              </li>
            ))}
          </ul>
        </div>
      )}
      <div className="fiches-lot space-y-3">
        {data.sheets.map((sheet) => (
          <article key={sheet.company_id} className="fiche-lot rounded-2xl border-2 border-slate-900 bg-white p-5" data-testid="import-sheet">
            <p className="text-lg font-bold text-indigo-800">JobDating YouCode</p>
            <p className="mt-2 text-2xl font-bold">{sheet.name}</p>
            {sheet.event && (
              <dl className="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                <dt className="text-slate-600">Événement</dt>
                <dd className="font-semibold">{sheet.event.name}</dd>
                <dt className="text-slate-600">Date</dt>
                <dd>{formatDate(sheet.event.date)}</dd>
                <dt className="text-slate-600">Lieu</dt>
                <dd>{sheet.event.location}</dd>
                <dt className="text-slate-600">Emplacement</dt>
                <dd>{placementLabel(sheet.event.floor, sheet.event.table_label) ?? 'À confirmer sur place'}</dd>
              </dl>
            )}
            {sheet.notice && <p className="mt-2 text-sm font-semibold text-amber-800">{sheet.notice}</p>}
            <dl className="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 rounded-lg bg-slate-100 p-4">
              <dt className="text-slate-600">Adresse</dt>
              <dd className="font-mono break-all">{sheet.url}</dd>
              <dt className="text-slate-600">Nom d’utilisateur</dt>
              <dd className="font-mono font-bold">{sheet.username}</dd>
              <dt className="text-slate-600">Mot de passe</dt>
              <dd className="font-mono font-bold">{sheet.password}</dd>
            </dl>
            <p className="mt-3 text-sm text-slate-600">En cas d’oubli, adressez-vous à l’équipe d’organisation, qui réinitialisera votre mot de passe.</p>
          </article>
        ))}
      </div>
    </div>
  )
}
