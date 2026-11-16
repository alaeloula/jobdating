/** Les 8 référentiels (ADM-09 et décisions du 16/11/2026) : champs affichés et saisis. */

export type ReferentialType =
  | 'filieres'
  | 'classes'
  | 'campus'
  | 'promotions'
  | 'secteurs'
  | 'competences'
  | 'opportunites'
  | 'etiquettes'

export type FieldKind = 'text' | 'email' | 'filiere' | 'tagType'

export interface FieldConfig {
  name: string
  label: string
  kind: FieldKind
  required: boolean
  /** Non modifiable après création (code de filière). */
  createOnly?: boolean
}

export interface ReferentialConfig {
  type: ReferentialType
  tab: string
  fields: FieldConfig[]
  orderable: boolean
}

export const TAG_TYPES = [
  { value: 'point_fort', label: 'Points forts' },
  { value: 'a_ameliorer', label: 'Points à améliorer' },
] as const

export const REFERENTIALS: ReferentialConfig[] = [
  {
    type: 'filieres',
    tab: 'Filières',
    orderable: false,
    fields: [
      { name: 'code', label: 'Code', kind: 'text', required: true, createOnly: true },
      { name: 'label', label: 'Libellé', kind: 'text', required: true },
    ],
  },
  {
    type: 'classes',
    tab: 'Classes',
    orderable: false,
    fields: [
      { name: 'name', label: 'Classe (nom du fichier)', kind: 'text', required: true },
      { name: 'filiere_id', label: 'Filière', kind: 'filiere', required: true },
    ],
  },
  {
    type: 'campus',
    tab: 'Campus',
    orderable: false,
    fields: [
      { name: 'name', label: 'Nom', kind: 'text', required: true },
      { name: 'city', label: 'Ville', kind: 'text', required: false },
      { name: 'contact_email', label: 'E-mail de contact', kind: 'email', required: false },
    ],
  },
  { type: 'promotions', tab: 'Promotions', orderable: false, fields: [{ name: 'label', label: 'Libellé', kind: 'text', required: true }] },
  { type: 'secteurs', tab: 'Secteurs', orderable: false, fields: [{ name: 'label', label: 'Libellé', kind: 'text', required: true }] },
  { type: 'competences', tab: 'Compétences', orderable: false, fields: [{ name: 'label', label: 'Libellé', kind: 'text', required: true }] },
  { type: 'opportunites', tab: 'Opportunités', orderable: true, fields: [{ name: 'label', label: 'Libellé', kind: 'text', required: true }] },
  {
    type: 'etiquettes',
    tab: 'Étiquettes',
    orderable: true,
    fields: [
      { name: 'type', label: 'Type', kind: 'tagType', required: true },
      { name: 'label', label: 'Libellé', kind: 'text', required: true },
    ],
  },
]
