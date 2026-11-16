# Plan – T-02 (JD-9) : référentiels

Statut : **version 3, validée par le responsable technique** (après la revue et la contre-revue du plan par Codex, `docs/reviews/REVIEW-PLAN-T-02.md` et `REVIEW-PLAN-T-02-contre-revue.md` ; les 3 derniers points sont intégrés, développement lancé sans nouvelle revue du plan, la revue du code les vérifiera).

Branche : `feature/T-02-referentiels` (créée depuis `develop`).

## Références

- CDC V2 : **ADM-09** (RG-REF-01 à 03), **8.2** (entités `filieres`, `classes`, `campuses`, `promotions`, `opportunity_types`, `evaluation_tags_ref`), **ENT-05** et **PC-23** (libellés validés de la grille), **RG-ENTR-02** (secteur), matrice **3.2** (« Gérer les référentiels » : administrateur et CME), **TRA-01**.
- Maquette : `maquettes/admin-12-referentiels.png` (onglets, tableau avec statut, formulaire « Ajouter », note « une valeur utilisée n'est jamais supprimée »).
- Décisions du 16/11/2026 (`docs/DECISIONS.md`) : **compétences** (nouveau référentiel), **table des secteurs**, **pas de suppression**, **filière d'une classe protégée dès qu'elle est utilisée**, **journalisation**, **pas de données initiales** pour les listes officielles ; précisions après la revue du plan (compétence désactivée, type d'une étiquette).

## Périmètre

**8 référentiels**, gérés par la permission existante `referentials.manage` (administrateur, CME) :

| Référentiel | Table | Champs | Unicité |
|---|---|---|---|
| Filières | `filieres` | code (définitif après création), libellé, actif | code |
| Classes | `classes` (modèle `SchoolClass`) | nom (tel que dans le fichier YouCode School Admin), filière (clé étrangère obligatoire), actif | nom |
| Campus | `campuses` | nom, ville, e-mail de contact (facultatif), actif | nom |
| Promotions | `promotions` | libellé, actif | libellé |
| Secteurs d'activité | `sectors` | libellé, actif | libellé |
| Types d'opportunité | `opportunity_types` | libellé, ordre, clé d'initialisation, actif | libellé ; clé d'initialisation |
| Étiquettes d'évaluation | `evaluation_tags_ref` | libellé, type (point fort / à améliorer), ordre (par type), clé d'initialisation, actif | type + libellé ; clé d'initialisation |
| Compétences | `competences` | libellé, actif | libellé |

Noms de tables conformes au CDC 8.2 : `evaluation_tags` reste réservé à l'association évaluation ↔ étiquettes (T-31).

### Règles

- **RG-REF-01** : les listes de choix de l'interface viendront de ces tables (utilisées par les tâches suivantes).
- **Aucune suppression** (décision du 16/11, plus stricte que RG-REF-02) : une valeur se désactive ou son libellé se corrige. Aucune route de suppression.
- **Code d'une filière** : définitif après création (« code stable », ADM-09) ; seul le libellé se corrige.
- **Filière d'une classe** (décision précisée le 16/11) : modifiable même si la classe est utilisée, pour corriger une erreur d'affectation après l'import. La filière d'un apprenant est toujours lue via sa classe : changer la filière d'une classe change celle de ses apprenants. Si la classe est utilisée, l'API refuse le changement **sans confirmation explicite** et l'accepte avec la confirmation ; l'écran affiche « N apprenants changeront de filière » et demande de confirmer. Le changement est journalisé. Contrat :
  - champ `confirmer_changement_filiere` ; **seule la valeur booléenne `true`** vaut confirmation ; absent ou `false` alors que la classe est utilisée → **409** (`code: class_in_use`, `learners_count` recalculé par le serveur) ; toute autre valeur (chaîne, nombre…) → **422** ;
  - un 409 ou un 422 ne modifie **aucun** champ de la requête et n'écrit rien dans le journal ;
  - une confirmation peut être envoyée directement, sans 409 préalable ;
  - le nombre d'apprenants est toujours recalculé par le serveur, jamais lu dans la requête. Le nombre d'apprenants vient d'un **point d'extension** (« combien d'apprenants dans cette classe ? ») qui répond 0 tant que la table des apprenants n'existe pas, que les tests peuvent simuler, et que **T-12 devra obligatoirement brancher**. Aucune table d'apprenants factice.
- **Type d'une étiquette** : modifiable tant qu'aucune évaluation ne l'utilise ; sinon refusé (créer une nouvelle étiquette et désactiver l'ancienne). Même mécanisme de point d'extension, branché par T-31.
- **Compétence désactivée** : ne compte plus pour les suggestions (T-40) ; ses associations restent conservées.
- **Coordination des contrôles d'utilisation (concurrence)** : la modification de la filière d'une classe et celle du type d'une étiquette se font dans une transaction qui **verrouille la ligne** (`SELECT … FOR UPDATE`), **refait le contrôle d'utilisation sous ce verrou**, puis modifie et journalise. **Obligation pour T-12, T-13 et T-31** : la première affectation d'un apprenant à une classe, et l'association d'une étiquette à une évaluation, verrouillent la même ligne (classe ou étiquette) dans leur transaction. Les tests de simultanéité sur MySQL seront écrits dans ces tâches, quand les relations existeront.

### Trois usages de lecture (contrat pour les tâches suivantes)

1. **Saisie** (nouvelles affectations) : `GET /api/v1/referentials/{type}/options` renvoie les **valeurs actives** seulement, avec les champs utiles au choix (identifiant, libellé, code pour les filières, filière pour les classes, type pour les étiquettes ; **pas** l'e-mail de contact des campus). Ouvert à tout utilisateur connecté.
2. **Affichage d'une donnée existante** : chaque ressource consommatrice (apprenant, entreprise, évaluation, suivi…) renvoie la valeur qui lui est liée (identifiant, libellé, état actif), **même désactivée**. Une modification qui ne change pas cette valeur ne l'efface pas. À réaliser et tester dans chaque tâche consommatrice.
3. **Filtres historiques** : `GET /api/v1/referentials/{type}/options?inactives=1` renvoie aussi les valeurs désactivées, signalées comme telles, pour les écrans de filtres et de statistiques. Ces listes ne contiennent aucune donnée sensible ; l'accès aux données filtrées reste contrôlé par chaque écran.

### Journal d'audit (intégration T-06)

- Nouvelle catégorie **« Référentiels »** ; nouvelles actions (valeur ajoutée, modifiée, désactivée, réactivée, ordre modifié) **rattachées explicitement** à cette catégorie (pas de catégorie par défaut).
- **Libellé de la valeur** enregistré dans le journal (type de référentiel + libellé), en plus des valeurs avant / après.
- Écriture d'audit dans la **même transaction** que la modification, y compris pour le réordonnancement.
- Le seeder ne journalise que ce qu'il **crée réellement** (auteur « Système ») ; une relance sans changement n'écrit rien.
- Le test des catégories du journal (6 → 7) et le filtre « Référentiels » de l'écran du journal sont vérifiés.

### Données initiales

Seeder `ReferentialsSeeder` (idempotent, utilisable en production, appelé par `DatabaseSeeder`) :
- opportunités validées PC-23 : Stage de pré-embauche, Emploi (CDI / CDD), Alternance, Freelance / mission, Autre ;
- étiquettes d'ENT-05 — points forts : Bonnes bases techniques, Projets personnels solides, Bon relationnel, Curieux, Bon niveau d'anglais ; à améliorer : Manque d'expérience pratique, CV à améliorer, Présentation à travailler.

Chaque valeur livrée porte une **clé d'initialisation fixe** (par exemple `opportunity.stage_pre_embauche`, `tag.strength.curieux`), distincte du libellé, du type et de l'ordre. Pour chaque valeur livrée, le seeder :
1. ne fait **rien** si une ligne porte déjà cette clé (libellé, état, ordre corrigés à l'écran conservés) ;
2. sinon, s'il trouve un **homonyme** selon l'unicité de la liste (libellé pour les opportunités ; **type + libellé** pour les étiquettes) **sans clé**, il lui **attribue la clé une seule fois**, sans modifier aucun champ métier (libellé, type, état, ordre) ; ce rattachement technique n'est pas journalisé comme une création ;
3. sinon, il **crée** la valeur (active, en fin d'ordre) et la journalise (auteur « Système »).

La clé est réservée au serveur : jamais acceptée ni renvoyée par l'API, et immuable une fois attribuée.

Filières, classes, campus, promotions, secteurs et compétences : **vides**, saisis à l'écran par l'administrateur avant le premier import.

### Hors périmètre

Utilisation des listes dans les formulaires et filtres (T-11, T-12, T-13, T-31, T-40…), avec l'usage 2 ci-dessus ; ajout d'une classe inconnue pendant l'aperçu d'import (T-13, en réutilisant les règles de T-02) ; compétences recherchées par l'entreprise (T-11) ; compétences des apprenants et leur permission (T-12) ; suggestion par compétence (T-40) ; saisie en masse des compétences (point ouvert COMP-01). Le critère « une filière ajoutée apparaît partout » ne sera vérifiable qu'à la livraison de ces tâches.

## Plan

**Backend**
1. Migrations des 8 tables (unicités, `active`, `position`, `seed_key` unique et facultative pour opportunités et étiquettes, clé étrangère classe → filière).
2. Modèles et énumération `ReferentialType` (table, champs, règles de validation, libellés) ; contrôleur commun ; points d'extension « nombre d'apprenants d'une classe » (T-12) et « étiquette utilisée » (T-31).
3. API de gestion (`permission:referentials.manage`) : `GET /api/v1/referentials/{type}` (toutes les valeurs), `POST /{type}` (ajout), `PATCH /{type}/{id}` (modification, activation / désactivation), `PUT /{type}/order` (ordre des opportunités ; des étiquettes **par type**). L'identifiant doit appartenir au type de l'adresse (sinon 404). Aucune suppression.
4. API de lecture `GET /api/v1/referentials/{type}/options` (actives) et `?inactives=1` (tous les états).
5. Journalisation via `AuditLogger` (catégorie et actions explicites, libellé du sujet, transaction).
6. Seeder `ReferentialsSeeder` (clés d'initialisation) appelé par `DatabaseSeeder`.

**Frontend**
7. Écran « Référentiels » (maquette `admin-12`) : 8 onglets, tableau (valeur, champs propres, statut Actif / Inactif, « Modifier »), formulaire « Ajouter », activation / désactivation, ordre (flèches haut / bas) pour opportunités et étiquettes, étiquettes séparées en points forts et à améliorer, code de filière non modifiable après création, note « une valeur utilisée n'est jamais supprimée : elle est désactivée ». Lien « Référentiels » dans l'en-tête pour `referentials.manage`.

**Tests**
8. Backend :
   - droits sur **chaque** opération (liste, ajout, modification, changement d'état, ordre) : administrateur et CME autorisés ; Direction, Entreprise, Apprenant 403 ; 401 sans session ; lecture des options pour tout connecté ;
   - un identifiant d'un autre type dans l'adresse ne permet pas de modifier une autre liste ;
   - validation et unicité pour chaque type ; absence de route de suppression ;
   - code de filière définitif (refus de modification, libellé modifiable) ;
   - classe : filière obligatoire et existante ; filière modifiable directement si la classe n'est pas utilisée ; si le point d'extension simule N apprenants, refus 409 avec N sans confirmation, acceptation avec confirmation, et ligne de journal ;
   - étiquette : type modifiable si non utilisée, refusé si le point d'extension simule « utilisée » ;
   - ordre : identifiants d'un autre groupe ou d'un autre type refusés, doublons refusés, opération atomique, tri stable ;
   - lecture : options actives sans e-mail de campus ; `inactives=1` avec état ;
   - journal : catégorie « Référentiels », libellé du sujet, valeurs avant / après, filtre de l'écran du journal ;
   - seeder : après renommage, désactivation et réordonnancement d'une valeur initiale, une relance conserve identifiant, libellé, état, ordre et nombre de lignes, sans réintroduire l'ancien libellé et sans nouvelle ligne de journal ; **enchaînement homonyme manuel** → premier seeder (clé attribuée, champs inchangés, pas de journal de création) → renommage, désactivation, réordonnancement → relance (pas de réintroduction) ; homonyme dans **l'autre type** d'étiquette sans effet sur l'initialisation du bon type ;
   - confirmation de filière : champ absent, `false`, `"true"` (chaîne), `1` → 409 ou 422 selon le cas, aucun champ modifié, aucun journal ; `true` → modification ; nombre d'apprenants envoyé par le client ignoré.
9. Frontend (Vitest) : onglets, ajout, désactivation, ordre, code de filière en lecture seule après création, confirmation « N apprenants changeront de filière », accès refusé aux autres rôles.
