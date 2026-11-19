# Plan – T-12 (JD-17) : apprenants

Branche : `feature/T-12-apprenants` (base `develop`, `d6cb56f`).

**Version 2.1** (19/11/2026). Version 2 après la revue du plan par Codex (`docs/reviews/REVIEW-PLAN-T-12.md`, « Plan à revoir », 4 constats majeurs et 2 mineurs) : changements marqués **[v2]**. Contre-revue (`docs/reviews/REVIEW-PLAN-T-12-contre-revue.md`, « Plan validé après corrections mineures ») : 2 précisions marquées **[v2.1]**, appliquées sans nouvelle contre-revue (décision du responsable technique du 19/11).

## Références

1. CDC V2 : **ADM-03** (scénario, données, RG-APR-01 à 07, critères d'acceptation), RG-AUTH-12, matrice 3.2 (lignes « apprenant », « CNIE »), modèle 8.2 (`learners`, `learner_competence`), TRA-01, EXI-DATA-03, PC-15, PC-22.
2. `docs/DECISIONS.md` :
   - 14/11 : première connexion de l'apprenant **sans changement obligatoire** du mot de passe ; réinitialisation par l'administrateur (affiché une fois, envoyé par e-mail, toutes les sessions fermées, journalisée) ;
   - 16/11 : **compétences des apprenants** saisies par l'administrateur et la CME, **sans niveau** (remplace la colonne `niveau` du modèle 8.2) ; nouvelle permission « Renseigner les compétences des apprenants » ; CNIE masquée dans le journal (2 derniers caractères) ;
   - 16/11 (après T-02) : une compétence désactivée ne compte plus pour les suggestions ; ses associations restent ; **filière d'une classe** modifiable avec confirmation si la classe est utilisée ;
   - **19/11 : « Apprenants (T-12) »** (8 réponses ci-dessous ; tranche COMP-01).
3. Contrat T-02 (`docs/tasks/PLAN-T-02.md`) **[v2]** : « Filière d'une classe », « Coordination des contrôles d'utilisation », « Trois usages de lecture » ; point d'extension `App\Contracts\ClassUsage` (`NoClassUsage` branché provisoirement dans `AppServiceProvider`, **T-12 doit le remplacer**).
4. Maquettes : `admin-06-apprenants.png` (liste, filtres, sélection, « Désactiver la sélection »), `admin-09-ajout-apprenant.png` (ajout).
5. Réutilisé : `PasswordGenerator`, `StrongPassword`, `GeneratedPasswordNotification` (file chiffrée), `SessionRevoker::closeAll`, `PasswordTokenService::revokeAll`, `AuditLogger` (masquage `cnie`), modèle de liste et de réponse `no-store` de T-10 / T-11 ; référentiels `campuses`, `promotions`, `classes` (avec `filiere_id`), `competences` (T-02).

> **Décision du 19/11, prise pendant la revue du code** (`DECISIONS.md`, « CNIE visible par l'administrateur, la CME et la Direction ») : elle **remplace la décision 3** et les passages du plan sur le masquage de la CNIE dans la liste, son absence pour la CME et la Direction, la recherche par CNIE réservée à l'administrateur (H5) et la journalisation de chaque consultation. La CNIE reste **jamais visible par les entreprises** et masquée dans le journal d'audit ; le téléphone reste réservé à l'administrateur.

## Décisions du responsable technique (19/11)

| # | Question | Réponse |
|---|---|---|
| 1 | Inscription à l'événement courant (`admin-09`) | **Reportée à T-14** (les événements n'existent pas). T-14 devra inscrire les apprenants existants. |
| 2 | Suppression d'une fiche (ADM-03, étape 4) | **Suppression logique avec restauration**, comme T-10 ; l'historique reste. |
| 3 | CNIE dans la liste | **Masquée dans la liste** (`••••••42`, 2 derniers caractères) ; **complète dans la fiche** de l'administrateur, chaque ouverture étant journalisée (RG-APR-06). |
| 4 | Mot de passe à la création | Envoyé par e-mail **et affiché une fois** à l'administrateur, comme la réinitialisation. |
| 5 | Compétences, saisie en masse (COMP-01) | Saisie **fiche par fiche** et action **« Ajouter une compétence à la sélection »** (même sélection que la désactivation en masse). |
| 6 | Sélection pour la désactivation en masse | Cases à cocher **et** « Sélectionner les N apprenants du filtre » (toutes pages). Le serveur **recompte** avant d'agir. Réactivation **individuelle** seulement. |
| 7 | Liste pour la CME et la Direction | **Lecture seule, sans CNIE ni téléphone** ; la CME y saisit les compétences. |
| 8 | État du CV dans la fiche (critère ADM-03) | **Reporté à T-20.** |
| 9 **[v2]** | Format de la CNIE (H1, question 1 de Codex) | **Lettres et chiffres, 5 à 30 caractères**, pour la CNIE, le passeport et la carte de séjour. Seuls les **espaces** sont retirés ; tout autre caractère est **refusé avec un message clair**, jamais supprimé en silence. Majuscules. Identifiant conservé en **chaîne** (zéros initiaux compris). |
| 10 **[v2]** | Ajout d'une compétence en masse (question 2) | S'applique à **tous les apprenants non supprimés**, actifs **et** désactivés. La désactivation en masse ne touche que les **actifs**. |
| 11 **[v2]** | Comptage des classes (question 3) | Le compteur de `ClassUsage` compte **toutes les fiches** de la classe : actives, désactivées **et** supprimées logiquement (leur historique et les statistiques dépendent de la filière). |

## Périmètre

### 1. Modèle de données (migration)

| Table | Colonnes | Contraintes |
|---|---|---|
| `learners` | `id`, `user_id`, `cnie` (chaîne, 30), `campus_id`, `promotion_id`, `class_id`, `cme_learner_id` (chaîne, 100, nullable), timestamps | `user_id` unique, clé vers `users` (`restrictOnDelete`) ; `cnie` **unique** ; `cme_learner_id` unique (plusieurs `NULL` permis, RG-APR-02 ; format définitif lié à PC-26) ; clés vers `campuses`, `promotions`, `classes` (`restrictOnDelete`) ; index `(promotion_id)`, `(campus_id)`, `(class_id)` |
| `learner_competence` | `learner_id`, `competence_id`, `created_at` | clé primaire composée ; `learner_id` → `learners` (`cascadeOnDelete`), `competence_id` → `competences` (`restrictOnDelete`) ; **pas de niveau** (décision du 16/11) |

- Identité dans `users` : `first_name`, `last_name`, `email` (**e-mail professionnel**, identifiant de connexion, déjà unique), `phone` (colonne existante). `username` vide.
- **La filière n'est pas stockée** : elle est celle de la classe (RG-APR-01).
- **CNIE** : règle unique dans `App\Support\Cnie` **[v2]** (`normalize()` : espaces retirés, majuscules ; `isValid()` : `\A[A-Z0-9]{5,30}\z` **[v2.1]**, ancrage strict : une CNIE suivie d'un retour à la ligne est refusée) utilisée par la validation, la recherche et T-13 (décision 9).

### 2. Permissions (`app/Enums/Permission.php`, `Role.php`)

- Nouvelle permission **`learners.competences.manage`** : administrateur et CME (décision du 16/11).
- Existantes : `learners.view` (administrateur, CME, Direction, **entreprise**), `learners.update` et `learners.view_cnie` (administrateur seul).
- L'entreprise a `learners.view` pour T-23 (participants de ses événements). Les routes de T-12 sont donc limitées aux **rôles** administrateur, CME et Direction, en plus de la permission ; l'entreprise et l'apprenant reçoivent **403**. T-23 ajoutera sa propre route limitée au périmètre, sans ouvrir `/learners`.
- `PermissionMatrixTest` mis à jour.

### 3. Comptage des classes : `ClassUsage` **[v2]** (constat 1)

- Nouvelle implémentation `App\Services\Learners\LearnerClassUsage` : `learnersCount(classe)` = nombre de lignes `learners` de la classe, **toutes** (décision 11). Liaison dans `AppServiceProvider` à la place de `NoClassUsage` (supprimé).
- **Protocole de verrouillage** (contrat T-02) : toute affectation d'un apprenant à une classe se fait dans une transaction qui **verrouille d'abord la ligne de la classe** (`SELECT … FOR UPDATE`), puis le compte et la fiche. **Ordre unique**, documenté dans `LearnerService` et réutilisé par T-13 : **classes** (par identifiant croissant ; ancienne et nouvelle lors d'un changement) → **compte `users`** → **fiche `learners`**.
- S'applique à `create` et à `update` quand la classe change. Les autres modifications ne verrouillent pas la classe.
- Tests : avec de vraies fiches, changement de filière d'une classe utilisée → **409** sans confirmation, accepté avec confirmation, nombre correct (fiches désactivées et supprimées comprises) ; test de concurrence sur MySQL (création d'un apprenant pendant le changement de filière : le nombre affiché n'est jamais en retard).

### 4. Service `App\Services\Learners\LearnerService`

Toutes les écritures en transaction, lignes verrouillées dans l'ordre du §3 (`lockForUpdate`), rejouées en cas d'interblocage comme T-10 / T-11. **L'état du compte est relu sous le verrou du compte `users`** [v2] (désactivation, suppression, mot de passe), pas seulement sous celui de la fiche.

- **`create(data, admin)`** : compte `users` (rôle apprenant, `activated_at` renseigné, mot de passe `PasswordGenerator`) + fiche `learners`. Campus, promotion et classe **actifs** exigés. CNIE, e-mail et identifiant CME uniques, **comptes supprimés compris** ; message clair si la CNIE appartient à une fiche supprimée (« restaurez-la »). Collision au dernier moment (contrainte unique) → 422, pas 500, **sans CNIE dans le message** [v2]. Après validation **finale** de la transaction (englobante comprise, `afterCommit`) : e-mail de **création** [v2] ; mot de passe renvoyé **une fois** (`no-store`), jamais journalisé. Une transaction annulée n'envoie **aucun** e-mail.
- **`update(learner, data, admin)`** : tous les champs (CNIE, prénom, nom, e-mail, téléphone, campus, promotion, classe, identifiant CME). Une valeur de référentiel **déjà associée** peut rester même devenue inactive ; une **nouvelle** valeur doit être active. Journal : champs modifiés seulement, avant / après, CNIE masquée. **[v2] (constat 3)** Si l'e-mail **change effectivement** : les liens de réinitialisation en attente sont **annulés** dans la même transaction, sous le verrou du compte ; une modification sans changement d'adresse ne les annule pas.
- **`resetPassword(learner, admin)`** : nouveau mot de passe, liens annulés, **toutes les sessions fermées** (`closeAll`), e-mail de **réinitialisation** après validation, affiché une fois ; refusé pour un compte désactivé ou supprimé ; journalisé avec l'administrateur comme auteur.
- **`deactivate` / `reactivate`** : sessions fermées et liens annulés à la désactivation (RG-APR-05) ; réactivation par l'administrateur seul.
- **`delete` / `restore`** : suppression logique du compte (`users.deleted_at`) ; **sessions fermées et liens annulés** [v2], comme T-10 ; la fiche `learners` et ses associations restent ; la restauration conserve l'état de désactivation antérieur et ne réactive aucun ancien lien.
- **`updateCompetences(learner, competenceIds, actor)`** : liste complète ; nouvelle compétence **active** exigée, association devenue inactive conservée tant qu'elle n'est pas retirée ; journal avant / après (libellés). Apprenant non supprimé (actif ou désactivé).
- **Actions en masse** : voir §5.

### 5. Actions en masse : contrat unique [v2] (constats 2 et 4)

**Sélection** : soit `{ ids: [...] }` (1 à 2 000 identifiants entiers distincts), soit `{ filters: {...} }` (mêmes filtres que la liste) ; **jamais les deux** (422). Identifiants inexistants ou non visibles → 422.

**Éligibilité** (même règle pour le comptage et l'action) :

| Action | Apprenants concernés | Ignorés (annoncés) |
|---|---|---|
| Désactivation (`bulk-deactivate`, administrateur) | actifs, non supprimés | déjà désactivés ; supprimés |
| Ajout d'une compétence (`bulk-competences`, administrateur et CME) | non supprimés (actifs et désactivés) n'ayant pas la compétence | ayant déjà la compétence ; supprimés |

**Comptage** : `POST /learners/selection/count` `{ ids | filters, action, competence_id? }` → `{ selected, eligible }`. L'écran affiche « **N sélectionnés, dont M concernés** » ; si M = 0, l'action est désactivée.

**Exécution** : `{ ids | filters, expected_eligible, competence_id? }` ; **l'action est déterminée par la route** (`bulk-deactivate` ou `bulk-competences`), jamais par la requête **[v2.1]** ; pour le comptage, `action` est validée et le droit correspondant vérifié (désactivation : administrateur). Dans **une** transaction, le serveur recalcule l'ensemble éligible, **verrouille les comptes dans l'ordre des identifiants**, compare au nombre attendu, puis écrit. Si le nombre diffère → **409** `selection_changed` avec le nouveau nombre ; **aucune écriture ni journal**. **[v2.1]** 0 attendu et 0 trouvé → 200 `{ changed: 0 }` sans aucune écriture ni journal ; attendu non nul et 0 trouvé → 409. Un apprenant supprimé envoyé par la CME est **invisible** pour elle → 422 (comme un identifiant inexistant) ; visible par l'administrateur, il est sélectionnable mais jamais éligible. Limite **2 000** comptes éligibles (H2) ; au-delà → 422 « Affinez le filtre ». Aucun appel réseau dans la transaction (e-mails : aucun pour ces actions).

**Journal sans CNIE** : une ligne par apprenant modifié et une ligne de **synthèse** (`learner.bulk_deactivated`, `learner.bulk_competence_added`) avec le nombre et une **description des filtres sans la recherche libre** : campus, promotion, classe, filière, compétence, statut (libellés) ; la recherche est notée « recherche : oui », jamais sa valeur. Les filtres bruts ne sont jamais recopiés.

**CNIE hors des adresses** : la recherche se fait par **`POST /learners/search`** (corps JSON), pas par paramètre d'URL : elle n'apparaît donc pas dans les journaux d'accès du serveur web. La liste sans recherche par CNIE reste aussi servie par cette route (une seule logique de filtres pour la liste, le comptage et les actions).

**Frontend** : un changement de recherche, de filtre ou de page **vide la sélection** « tout le filtre » (les cases cochées restent limitées à la page) ; le bandeau rappelle les filtres figés au moment de la sélection. Après un 409 : nouveau nombre affiché et **nouvelle confirmation explicite**, jamais de relance automatique.

### 6. API `/api/v1`

| Méthode et route | Qui | Rôle |
|---|---|---|
| `POST /learners/search` [v2] | admin, CME, Direction | liste paginée (25) : recherche (prénom, nom, e-mail ; **CNIE exacte normalisée pour l'administrateur seulement**, H5), filtres statut (actif / désactivé ; supprimé pour l'administrateur), campus, promotion, classe, filière, compétence ; tri nom puis id |
| `POST /learners/selection/count` [v2] | admin, CME | `{ selected, eligible }` (§5) |
| `GET /learners/{id}` | admin, CME, Direction | fiche ; **CNIE complète et téléphone pour l'administrateur seulement** ; l'ouverture par l'administrateur crée une ligne « CNIE consultée » (catégorie CNIE) |
| `POST /learners` | admin | création ; réponse `no-store` avec le mot de passe affiché une fois |
| `PUT /learners/{id}` | admin | modification |
| `POST /learners/{id}/reset-password` | admin | réinitialisation ; `no-store` |
| `POST /learners/{id}/deactivate`, `/reactivate` | admin | |
| `DELETE /learners/{id}`, `POST /learners/{id}/restore` | admin | |
| `POST /learners/bulk-deactivate` | admin | §5 |
| `PUT /learners/{id}/competences` | admin, CME | `{ competence_ids: [] }` |
| `POST /learners/bulk-competences` | admin, CME | §5 |

- Ressource `LearnerResource` : CNIE **masquée** dans la liste pour l'administrateur ; **absente** (ni masquée) pour la CME et la Direction, de même que le téléphone. Champs : prénom, nom, e-mail, campus, promotion, classe, filière (déduite), compétences, statut, identifiant CME (administrateur).
- **[v2] (constat 5)** Campus, promotion, classe, filière et compétences liés sont renvoyés avec **identifiant, libellé et état actif**, même inactifs (usage 2 du contrat T-02).
- La CNIE n'apparaît dans **aucune** réponse d'erreur, aucun message de validation, aucune collision SQL renvoyée, aucun journal technique (RG-APR-06).

### 7. Journal (TRA-01)

- Actions : `learner.created`, `learner.updated`, `learner.competences_updated`, `learner.cnie_viewed` (catégorie **CNIE**), `learner.bulk_deactivated`, `learner.bulk_competence_added` [v2] (synthèses) ; désactivation, réactivation, suppression, restauration et réinitialisation : actions de compte existantes (`account.*`, catégorie Comptes). Sujet : « Apprenant : prénom nom ».
- Nouvelle catégorie **« Apprenants »** pour les fiches, les compétences et les synthèses (H3, avis favorable de Codex) ; filtre du journal et tests mis à jour.
- Une modification de compte faite par `LearnerService` n'est pas journalisée une seconde fois par l'observateur des comptes (`User::withoutAccountAudit`, comme T-11) [v2].
- Jamais de mot de passe ; CNIE masquée par `AuditLogger` ; jamais de recherche libre (§5).

### 8. E-mails [v2] (constat 6)

- `GeneratedPasswordNotification` reçoit un **motif** : `création` (« Votre compte JobDating a été créé… », sans mention de sessions fermées) ou `réinitialisation` (texte actuel). Toujours en file chiffrée, envoyé après la validation finale. Tests du texte des deux cas.

### 9. Frontend

- **Écran « Apprenants »** (`/apprenants`, administrateur, CME, Direction ; lien de navigation après « Entreprises participantes », ordre de la maquette `admin-01`) :
  - filtres « Actifs / Désactivés » (et « Supprimés » pour l'administrateur), campus, promotion, classe, filière, compétence ; **options historiques** (`?inactives=1`) pour les filtres [v2] ; recherche ; pagination ;
  - colonnes de la maquette `admin-06` (CNIE masquée, administrateur seulement) ;
  - **sélection** (administrateur et CME, jamais la Direction) : cases à cocher, « Sélectionner les N apprenants du filtre », bandeau « N sélectionnés, dont M concernés » ; administrateur : « Désactiver la sélection » avec confirmation « M comptes vont être désactivés » ; administrateur et CME : « Ajouter une compétence à la sélection » ; règles du §5 ;
  - actions par ligne (administrateur) : Modifier, Réinitialiser le mot de passe, Désactiver / Réactiver, Supprimer / Restaurer ; CME : Compétences.
- **Formulaire** d'ajout et de modification (maquette `admin-09`, sans l'inscription à l'événement) : classe choisie → « Filière déduite : … » ; encadré « Ce qui se passe à la création » ; **la valeur actuelle inactive reste proposée** (« (inactive) ») [v2].
- **Mot de passe affiché une fois** (création, réinitialisation) avec « Copier », absent du cache des requêtes **et des mutations** ; il disparaît à la fermeture ou au changement de fiche.
- **Compétences** : cases à cocher (compétences actives ; associations inactives affichées « (inactive) »).
- Chaque message d'erreur disparaît dès que l'utilisateur corrige le champ ; enchaînements « 422 → correction → nouvel essai » et « 409 → nouvelle confirmation » testés.

### 10. Données de démonstration

`DemoAccountsSeeder` : fiche `learners` pour `apprenant.demo@jobdating.test` avec une CNIE **fictive**, si un campus, une promotion et une classe actifs existent ; **sinon la fiche est ignorée avec un message** (aucune valeur officielle créée implicitement) [v2]. Relance sans doublon.

## Hors périmètre

Inscription à l'événement (**T-14**) ; import du fichier YouCode School Admin et fichier des mots de passe (**T-13**) ; CV et état du CV dans la fiche (**T-20 à T-22**) ; liste côté entreprise (**T-23**) ; suggestions d'entreprises (**T-40**) ; export (**T-61**) ; réactivation en masse ; débordement mobile de « Entreprises » (E-04, T-49).

## Hypothèses

- **H1** : tranchée (décision 9).
- **H2** : 2 000 apprenants éligibles au plus par action en masse ; **mesure sur MySQL** après implémentation (désactivation de 2 000 comptes avec fermeture des sessions et journal) ; sans découpage ni file tant que la mesure ne le justifie pas.
- **H3** : catégorie « Apprenants » (avis favorable).
- **H4** : confirmée par la décision 7 (Direction en lecture seule, sans sélection).
- **H5** : recherche par CNIE exacte normalisée, réservée à l'administrateur, aussi dans le comptage et les actions en masse.

## Passages de relais

- **T-13** : réutiliser `Cnie`, `LearnerService::create` / `update` dans la transaction de l'import : même ordre de verrouillage (classe → compte → fiche), annulation des liens quand l'e-mail change, e-mails envoyés **seulement à la validation finale** (une annulation n'envoie rien), statut actif / désactivé appliqué sans réinitialiser les comptes existants.
- **T-14** : inscrire automatiquement les apprenants actifs à l'événement courant ; un apprenant ajouté à la main y est inscrit.
- **T-20** : afficher l'état du CV dans la fiche (présence, source, date, auteur ; critère ADM-03).
- **T-23 / T-24** : routes entreprise distinctes, données de PC-15 seulement (ni CNIE ni téléphone), apprenants désactivés exclus.
- **T-40** : suggestions à partir de la filière (de la classe) et des compétences **actives**.

## Plan

0. **[v2.1] Points d'application de la contre-revue** : si la classe d'un apprenant a changé pendant l'attente du verrou, l'opération recommence avec les bonnes classes (jamais de verrou de classe pris après le compte) ; T-13 devra prendre les verrous de toutes les classes de l'import dans l'ordre croissant.
1. Migration, modèles `Learner` (relations `user` avec les supprimés, `campus`, `promotion`, `schoolClass`, `competences`), `Cnie`, `LearnerClassUsage` et sa liaison, permission et matrice.
2. `LearnerService` (verrouillage, e-mails, liens), actions en masse, contrôleur, ressource, routes ; journal ; notification à deux motifs.
3. Tests backend.
4. Frontend : API, liste, sélection, formulaire, mot de passe affiché une fois, compétences ; navigation.
5. Données de démonstration ; `PROGRESS.md` ; contrôles ; mesure H2 ; prompt de revue du code.

## Tests (résumé)

- **Droits** : chaque route pour les 5 rôles (entreprise et apprenant : 403) ; CME sans CNIE ni téléphone ni action de compte ; Direction en lecture seule, sans sélection ; nouvelle permission sur chaque opération de compétences.
- **CNIE** : unique (y compris supprimés), normalisée (espaces, majuscules), caractères interdits refusés (dont un retour à la ligne final [v2.1]), masquée dans la liste, absente pour la CME et la Direction, jamais en clair dans le journal, les erreurs de validation, les collisions SQL (création **et** modification), ni les synthèses en masse ; consultation journalisée ; recherche réservée à l'administrateur.
- **Classes** [v2] : 409 / confirmation / nombre correct avec de vraies fiches ; concurrence MySQL.
- **Création** : compte activé, rôle apprenant, e-mail de création après validation seulement (aucun si annulation), mot de passe affiché une fois et hors journal, référentiels actifs exigés, collisions au dernier moment → 422.
- **Modification** : référentiel inactif conservé, nouveau actif exigé ; journal des seuls champs modifiés ; **ancien lien → changement d'e-mail → lien refusé** ; même e-mail → lien conservé [v2].
- **Réinitialisation** : ancien mot de passe refusé, sessions fermées, e-mail de réinitialisation, refus pour un compte désactivé ; concurrence avec un changement de mot de passe et une désactivation (état relu sous le verrou du compte).
- **Masse** [v2] : modes `ids` et `filters`, mélange refusé, action fixée par la route (champ `action` du corps ignoré à l'exécution) et 0 / 0 → 200 sans effet, apprenant supprimé envoyé par la CME → 422 [v2.1], identifiants invalides, sélections mixtes (actifs, désactivés, ayant déjà la compétence), comptage `selected` / `eligible`, 409 sans écriture ni journal puis nouvelle confirmation et succès, limite H2 dépassée, **échec du journal au milieu : rien n'est modifié** (comptes, sessions, liens, compétences), journal par compte et synthèse sans recherche libre.
- **Suppression / restauration** : sessions fermées, liens annulés, état de désactivation conservé.
- **Compétences** : nouvelle active exigée, inactive conservée, ajout en masse idempotent.
- **Référentiels inactifs à l'écran** [v2] : modifier un autre champ sans perdre l'association inactive ; filtrer sur une promotion ou une classe désactivée.
- **E-mails** : texte de création et de réinitialisation.
- **Frontend** : liste par rôle, sélection du filtre vidée au changement de filtre, « N sélectionnés, dont M concernés », 409 → nouvelle confirmation, mot de passe hors cache des requêtes et des mutations, erreurs qui disparaissent à la correction.
- **Seeder** : relance sans doublon ; référentiels vides → fiche ignorée avec message.
