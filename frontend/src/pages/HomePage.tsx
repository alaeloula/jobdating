import { useMe } from '../auth/queries'

/** Accueil d'un rôle. Vide pour l'instant : le contenu arrive avec les tâches suivantes. */
export default function HomePage({ title }: { title: string }) {
  const { data: user } = useMe()

  return (
    <section>
      <h1 className="text-2xl font-bold">{title}</h1>
      {user?.role === 'direction' && <p className="mt-2 text-sm text-slate-600">Lecture seule</p>}
    </section>
  )
}
