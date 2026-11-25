# Plan – T-14 (JD-19) : événements, participants et emplacements

Branche à créer : `feature/T-14-evenements` (base `develop`, `1b05242`).

**Version 1.4** (23/11/2026) : corrections de la revue Codex (`docs/reviews/REVIEW-PLAN-T-14.md`, 5 constats), décisions 10 à 14 et résidu R1 de la contre-revue (`docs/reviews/REVIEW-PLAN-T-14-contre-revue.md`, « Plan validé après corrections mineures »). **Aucun code n'est écrit.**

## Références

1. CDC V2 : **ADM-05** (RG-EVT-01 à 04), **ADM-07** (RG-PAR-01 à 07), RG-ENTR-03, RG-IMP-06 et 07, ENT-01 (événement et emplacement), APP-00 et APP-01, matrice 3.2, modèle 8.2 (`events`, `event_companies`, `event_learners`), TRA-01.
2. `docs/DECISIONS.md` : fuseau `Africa/Casablanca` ; entreprises sans e-mail ; T-11 et T-12 (événement reporté à T-14). **23/11 : « Événements et participants (T-14) »** (§ 1).
3. Relais : T-11 (première participation, emplacement facultatif), T-12 et T-13 (inscription, ordre des verrous), `ARCHITECTURE.md` (un seul événement courant, garanti et testé).
4. Maquettes : `admin-02` (événement, sans la partie programme), `admin-03` (participants), `admin-04` et `admin-05` (création et fiche), `cme-02` (emplacements), `entreprise-ordi-01` (encadré et emplacement), `apprenant-01` (événement et entreprises présentes).

## 1. Décisions du responsable technique (23/11)

| # | Sujet | Décision |
|---|---|---|
| 1 | Apprenant retiré d'un événement | **Il reste retiré** : ni l'import ni la réactivation ne l'annulent. Un bouton **« Réinscrire »** permet de revenir en arrière. |
| 2 | Événement à la création d'une entreprise | **Obligatoire.** L'événement courant est présélectionné. Étage et table restent facultatifs. |
| 3 | Archivage | **Désarchivable.** Archivé : lecture seule, visible dans « Éditions précédentes », jamais courant. |
| 4 | Affichage aux participants | **Tout dans T-14** : fiche de connexion, accueil entreprise et « Ma journée ». |
| 5 | Date d'un événement | **Non modifiable** dès qu'il a un passage. |
| 6 | Événement terminé | Il **ne peut pas devenir courant**. |
| 7 | Statut courant | Il **peut être retiré** sans en désigner un autre. |
| 8 | Entreprise désactivée | **Masquée aux apprenants**, visible pour l'administrateur et la CME. |
| 9 | Emplacement | La **dernière modification** enregistrée est conservée. |
| 10 | Événement affiché à l'entreprise | **L'événement courant seulement**, sans sélecteur. Si elle n'y participe pas : message explicatif. |
| 11 | « Fiche non imprimée » | La **version actuelle des identifiants** n'a pas été imprimée, toutes éditions confondues. Aucune colonne ajoutée. |
| 12 | Années précédentes | L'entreprise **ne voit plus** ses anciens passages. Ils restent conservés, visibles par l'administration et dans les statistiques. |
| 13 | Nom de l'événement | Se termine toujours par l'**année scolaire** (septembre à août), **ajoutée automatiquement** depuis la date : « JobDating 2026/2027 ». |
| 14 | Événements par année | **Un seul** par année scolaire. |

Contexte : chaque événement a ses propres entreprises, et celles de cette année ne sont pas encore connues. Les anciennes entreprises (T-16) ne sont **jamais** inscrites automatiquement.

## 2. Périmètre

**Inclus**

- Événements : création, modification, désignation comme courant, archivage, désarchivage, suppression, statut calculé, rappel à la création.
- Inscription automatique des apprenants actifs ; retrait et réinscription par l'administrateur.
- Entreprises : affectation à la création, ajout en masse depuis les autres entreprises (avec rapport), retrait.
- Emplacements : administrateur et CME.
- Affichage : fiche de connexion, encadré et emplacement de l'accueil entreprise, événement et entreprises présentes dans « Ma journée ».

**Exclus** : programme (**T-15**) ; reprise des anciennes entreprises (**T-16**) ; import des entreprises par fichier (tâche à créer) ; passages et compteurs réels (**T-30**, **T-33**) ; recherche d'apprenants côté entreprise (**T-23**) ; page complète « Entreprises », état du CV, suggestions (**T-20**, **T-40**).

## 3. Base de données

**`events`** : `id`, `name` (150, sans l'année), `school_year` (9 caractères, par exemple `2026/2027`), `date`, `location` (255), `latitude` et `longitude` (décimales, facultatives, ensemble ou pas du tout), `description` (facultative), `is_current`, `archived_at`, horodatage.

- Colonne générée `current_marker` (1 si courant, sinon NULL) avec **index unique** : la base elle-même garantit RG-EVT-01.
- **Index unique sur `school_year`** (décision 14), événements archivés compris. Le nom affiché partout est `name` + espace + `school_year`, écrit une seule fois dans le modèle.

**`event_companies`** : `id`, `event_id`, `company_id`, `floor` (50, facultatif), `table_label` (50, facultatif), horodatage ; unique (`event_id`, `company_id`).

- La colonne `statut` du modèle 8.2 **n'est pas créée** : le CDC ne définit aucune valeur (point signalé, § 13).

**`event_learners`** : `id`, `event_id`, `learner_id`, `enrolled_at`, `removed_at` (facultatif) ; unique (`event_id`, `learner_id`).

- Un retrait **garde la ligne** avec `removed_at` : c'est ce qui l'empêche d'être annulé par un import (décision 1).

**`event_locks`** : une seule ligne, sans donnée métier. Elle sert de verrou commun aux inscriptions (§ 6).

Clés étrangères : suppression en cascade depuis `events` ; `restrict` depuis `companies` et `learners`.

## 4. Règles des événements (`EventService`)

- **Statut calculé** (RG-EVT-02), fuseau `Africa/Casablanca`, jours entiers : À venir, En cours, Terminé. Jamais stocké.
- **Date** (RG-EVT-04) : pas antérieure au jour de **création** de l'événement, y compris en modification. Si l'événement a des passages, sa date n'est plus modifiable (décision 5).
- **Année scolaire** (décisions 13 et 14) : calculée par le serveur depuis la date de l'événement. Du 1er septembre 2026 au 31 août 2027 : `2026/2027`. Elle est recalculée si la date change. Une année déjà prise donne un 422 clair, y compris en concurrence grâce à l'index. Un nom saisi qui finit déjà par une année est refusé, avec le message « L'année est ajoutée automatiquement ».
- **Désigner comme courant** (RG-EVT-01) : retire ce statut au précédent et inscrit les apprenants actifs, dans une transaction. Refusé pour un événement archivé ou terminé (décision 6). Répété : aucun doublon, aucune ligne retirée n'est rétablie.
- **Rapport** (ADM-07) : inscrits, déjà inscrits, ignorés (désactivés, retirés). Comptes seulement, sans nom.
- **Retirer le statut courant** : permis, car le CDC dit « au plus un » (décision 7). Les inscriptions restent.
- **Après la date**, l'événement reste courant jusqu'à ce qu'un autre soit désigné (PC-04).
- **Archiver** : refusé pour l'événement courant. **Archivé** : aucune modification de l'événement ni de ses participants.
- **Supprimer** (RG-EVT-03) : refusé (409) s'il a des passages, avec proposition d'archiver ; refusé pour l'événement courant. Sinon, ses participations sont supprimées.
- **Point d'extension `EventUsage`** (même principe que `ClassUsage`), toujours **dans un événement donné** : `forEvent(event)`, `forCompany(event, company)`, `forLearner(event, learner)`. Chaque passage compte, avec ou sans évaluation, sans regrouper les rencontres d'un même couple. Un passage dans E1 n'empêche donc pas un retrait de E2. L'implémentation répond **0** tant que T-30 n'existe pas ; **T-30 doit la remplacer**.
- Rappel après création : « Pensez à désactiver les apprenants qui ne participent plus (ADM-03) et à ajouter les entreprises participantes. »

## 5. Participants (`EventParticipationService`)

**Apprenants**

- Participant visible = ligne non retirée, compte **actif** et non supprimé. Cette règle est écrite **une seule fois** (portée Eloquent), réutilisée par T-23 et suivantes.
- Un apprenant désactivé garde sa ligne et disparaît de l'événement (RG-IMP-06) ; réactivé, il réapparaît, **sauf s'il a été retiré**.
- Inscription à l'événement courant, si l'apprenant est actif : création à la main, import (lignes créées ou mises à jour), réactivation, restauration. Sans effet si aucun événement n'est courant.
- Retrait (RG-PAR-04) : refusé avec le message du CDC s'il a un passage. Réinscription : efface `removed_at`.
- Actions dans l'écran « Apprenants », pour l'administrateur, avec l'état « Inscrit », « Retiré » ou « Non inscrit ».
- **Données de démonstration** : `DemoAccountsSeeder` appelle la même inscription idempotente pour l'apprenant démo s'il existe un événement courant ; un retrait n'est jamais annulé. L'entreprise démo reste à ajouter par l'administrateur.

**Entreprises**

- Création : `event_id` **obligatoire**, parmi les événements non archivés et non terminés ; étage et table facultatifs. Sans événement possible, le formulaire l'explique et renvoie vers « Événement et programme ».
- **Transaction unique (revue Codex, constat 3)** : `CompanyService::create` reçoit l'affectation et fait tout dans **sa propre transaction**, sans transaction englobante : verrou de la ligne de l'événement, contrôle de son éligibilité, compte, fiche, participation, audit. Toute erreur passe par le nettoyage existant et supprime le nouveau logo. Archivage et suppression prennent le même verrou, donc ne peuvent pas se glisser entre le contrôle et l'écriture.
- **Vue de l'entreprise (décisions 10 et 12)** : une seule règle serveur, « l'événement courant, si elle y participe ». Aucun identifiant d'événement n'est accepté du navigateur. Une entreprise affectée à un événement futur non courant voit le message jusqu'à sa désignation. Si le statut courant est retiré (décision 7), toutes les entreprises voient ce message.
- **Fiche de connexion** : elle reçoit la participation concernée. À la création, celle qui vient d'être créée. À la réinitialisation par l'administrateur, `event_id` facultatif (écran « Entreprises participantes »), refusé (422) **avant tout effet** s'il n'est pas une participation de cette entreprise (mot de passe et version inchangés) ; sans lui, l'événement courant si elle y participe ; sinon, la fiche s'imprime sans événement.
- Les entreprises déjà créées restent sans participation et s'ajoutent depuis l'événement.
- Ajout en masse (RG-PAR-07) : sélection, puis rapport « ajoutées / déjà inscrites ». Comptes désactivés ou supprimés non proposés.
- Retrait (RG-PAR-04) : la ligne est supprimée ; refusé s'il y a un passage (« Retrait impossible »).
- Une entreprise désactivée n'apparaît plus aux apprenants ; elle reste visible, avec son état, pour l'administrateur et la CME (décision 8).

**Emplacements** : `floor` et `table_label`, facultatifs ; permission `locations.manage`. La dernière modification enregistrée est conservée (décision 9).

## 6. Concurrence

Risque : un apprenant créé pendant qu'un événement devient courant pourrait n'être inscrit nulle part.

**Ordre des verrous : `event_locks` → ligne de l'événement → classes → comptes → fiches.**

- Désigner ou retirer le courant : verrou **exclusif** sur `event_locks`.
- Créer, réactiver, restaurer un apprenant ou traiter un import : verrou **partagé** sur `event_locks`, pris **en premier** dans la transaction. L'import l'ajoute au début de chaque reprise.
- Modifier un apprenant, ajouter des compétences en masse ou désactiver en masse ne rendent personne actif : ces chemins **ne prennent pas** `event_locks` et gardent leur ordre actuel.

**Inscription en masse (revue Codex, constat 1).** Pas d'`insert ... select`, qui poserait des verrous sur les comptes et les fiches lus :

1. prendre le verrou exclusif, **avant toute lecture** de la transaction ;
2. lire les identifiants des apprenants actifs, **triés par identifiant croissant**, par une lecture simple, **sans verrou** : la photo de la base est prise après le verrou, donc elle contient toutes les créations et réactivations validées ;
3. insérer dans `event_learners` par paquets de 500, **dans ce même ordre croissant** (paquets et valeurs), en ignorant les doublons ; une ligne retirée n'est jamais modifiée.

Cette écriture ne verrouille pas `users`, mais la vérification des clés étrangères peut poser un **verrou partagé sur chaque fiche** référencée. L'ordre croissant, le même que celui des opérations en masse existantes, réduit le risque de cycle sans le supprimer : la reprise ci-dessous reste nécessaire. Un apprenant désactivé juste après la lecture garde au pire une ligne sans effet, car la règle « participant visible » exige un compte actif.

**Reprise.** La transaction entière est rejouée jusqu'à 3 fois en cas d'interblocage. Le journal est écrit dans cette transaction, donc une seule ligne subsiste, et aucun effet différé n'est lancé avant sa validation. Après 3 échecs : réponse 409 « Réessayez », rien n'est modifié.

**Participants et passages.**

- Retirer un participant, supprimer un événement ou modifier sa date : verrou **exclusif** sur la ligne de l'événement, **puis** contrôle de `EventUsage`, dans la même transaction.
- **Relais obligatoire pour T-30**, dans une seule transaction : verrou **partagé** sur la ligne de l'événement ; **relecture après ce verrou** de l'événement (non archivé), de la participation de l'entreprise et de celle de l'apprenant (non retiré, compte actif) ; puis création du passage. T-30 devra tester à deux connexions « retrait contre création de passage ».

## 7. API (`/api/v1`)

| Méthode et route | Droit |
|---|---|
| `GET /events`, `GET /events/{id}`, `POST /events`, `PATCH /events/{id}`, `DELETE /events/{id}` | `events.manage` |
| `POST /events/{id}/current`, `DELETE /events/current`, `POST /events/{id}/archive`, `POST /events/{id}/unarchive` | `events.manage` |
| `GET /events/{id}/companies` (filtre « sans emplacement ») | `participants.manage` ou `locations.manage` |
| `GET /events/{id}/available-companies`, `POST /events/{id}/companies`, `DELETE /events/{id}/companies/{company}` | `participants.manage` |
| `PATCH /events/{id}/companies/{company}/location` | `locations.manage` |
| `GET /event-options` (événement courant et événements possibles pour une création) | `companies.manage` |
| `GET /events/current/summary` (nom et date pour l'écran CME) | `locations.manage` |
| `DELETE /events/{id}/learners/{learner}`, `POST /events/{id}/learners/{learner}/reinstate` | `participants.manage` |
| `GET /me/company-event` (événement courant et emplacement, ou état vide) | rôle Entreprise, **sa** participation à l'événement courant uniquement ; aucun paramètre |
| `GET /me/learner-event` | rôle Apprenant, **son** inscription uniquement |

Réponses côté apprenant : événement, entreprises visibles avec nom, secteur, étage et table. **Jamais** l'identifiant de connexion, le contact ni aucune donnée d'évaluation. Un apprenant retiré, ou inscrit à aucun événement courant, reçoit un état vide.

## 8. Journal d'audit

Actions : `event.created`, `updated`, `current_set` (avec les comptes du rapport), `current_unset`, `archived`, `unarchived`, `deleted` ; `event_company.added`, `removed`, `location_updated` ; `event_learner.removed`, `reinstated`. L'inscription en masse donne **une seule ligne**, avec des comptes. Aucune CNIE.

## 9. Frontend

- **« Événement et programme »** (`/evenements`, administrateur ; `admin-02` sans le programme) : événement courant, formulaire avec **aperçu du nom complet** selon la date, statut, actions, rappel, rapport, « Éditions précédentes ».
- **« Entreprises participantes »** (`admin-03`) : événement affiché, filtres « Toutes », « Sans emplacement », « Fiche non imprimée », ajout en masse, emplacement, « Retirer » ou « Retrait impossible ». Le bouton « Importer (fichier) » **n'est pas affiché**.
- **Formulaire entreprise** (`admin-04`) : événement obligatoire, étage, table. **Fiche** (`admin-05`) : événement, date, emplacement.
- **« Emplacements des entreprises »** (`/emplacements`, CME et administrateur ; `cme-02`).
- **« Apprenants »** : état dans l'événement courant, « Retirer » et « Réinscrire » (administrateur).
- **Accueil entreprise** (`entreprise-ordi-01`) : encadré du menu et ligne « événement 2026/2027 · date · lieu · emplacement », **sans sélecteur**. Sans participation à l'événement courant : message explicatif. Compteurs, passages et programme **non affichés** tant que leurs tâches n'existent pas.
- **« Ma journée »** (`apprenant-01`) : événement et entreprises présentes. CV et programme **non affichés** tant que T-15 et T-20 n'existent pas.
- Tous les écrans prévoient chargement, erreur avec « Réessayer », réponse tardive ignorée et double clic bloqué.

## 10. Tests

**Backend (MySQL)**

- Matrice : chaque route pour les 5 rôles ; une entreprise et un apprenant ne voient que leurs propres données (APP-01).
- Statut à 23 h 59 et 00 h 00, heure du Maroc ; RG-EVT-04 ; validations.
- Un seul courant (service **et** index unique) ; rapport ; désignation répétée.
- Inscription : création, import, réactivation, restauration ; désactivé non inscrit ; **retiré jamais réinscrit** par import ou réactivation ; bouton « Réinscrire ».
- Retrait et suppression refusés avec un `EventUsage` simulé à 1 ; archivage et désarchivage ; archivé en lecture seule.
- Création d'entreprise sans événement, avec un événement archivé ou terminé : refusée, **rien n'est créé**, logo compris.
- **Échec après la création de la fiche** (participation ou audit) : ni compte, ni entreprise, ni participation, ni audit, ni logo restant. Création contre archivage et contre suppression de l'événement.
- **Vue de l'entreprise** : deux éditions et deux emplacements, seul le courant est renvoyé ; participation seulement à un événement futur non courant ou à une année passée : état vide ; aucune participation ; aucun courant ; paramètre `event_id` ignoré ; fiche après réinitialisation avec et sans `event_id`, événement d'une autre entreprise refusé.
- **Année scolaire** : 31/08/2026 → `2025/2026`, 01/09/2026 → `2026/2027`, 31/08/2027 → `2026/2027`, 01/09/2027 → `2027/2028` ; collision au dernier moment, à la création et au changement de date (422) ; changement de date ; deuxième événement sur la même année refusé (service, index et archivé) ; nom finissant déjà par une année refusé.
- `EventUsage` simulé distinguant E1 et E2 : passage dans E1, retrait de E2 permis.
- Import d'une ligne **inchangée** : un inscrit reste inscrit, un retiré reste retiré, sans changement de mot de passe ni notification.
- Seeder démo relancé : inscrit au courant, retrait conservé.
- Ajout en masse et rapport ; emplacement ; CME sans droit d'ajout ni de retrait.
- Aucune fuite (CNIE, identifiants, contact) ; journal.
- **Concurrence à deux connexions** contre la désignation du courant : création d'apprenant, import, `update`, `bulkAddCompetence`, `bulkDeactivate`. Le test `bulkAddCompetence` organise un **chevauchement réel sur les mêmes fiches** et contrôle les effets après reprise. Reprise après interblocage : une seule ligne de journal.

**Frontend** : parcours de chaque écran, états d'erreur, séquences « erreur → correction → nouvel essai », changement d'événement pendant une requête, réponses tardives, boutons selon le rôle, adaptation des tests T-11.

**Mesure** : désignation avec 2 000 apprenants actifs.

## 11. Fichiers concernés

- **Backend, nouveaux** : 4 migrations ; modèles `Event`, `EventCompany`, `EventLearner` ; `EventService`, `EventParticipationService`, `EventUsage` et son implémentation ; contrôleurs, ressources, routes ; tests.
- **Backend, modifiés** : `LearnerService`, `LearnerImportRunner`, `CompanyService`, `CompanyController`, `AuditAction`, `AppServiceProvider`, `DemoAccountsSeeder`, tests T-11 à T-13.
- **Frontend, nouveaux** : `src/events/`, `EventsPage`, `LocationsPage`, `CompanyHomePage`, `LearnerDayPage`.
- **Frontend, modifiés** : `AppRoutes`, `AppLayout`, `CompaniesPage`, `CompanyForm`, `CredentialsSheet`, `LearnersPage`.
- **Aucune nouvelle dépendance.** Aucune nouvelle permission.

## 12. `DECISIONS.md`

Les décisions 1 à 9 sont inscrites dans `docs/DECISIONS.md`, ligne du 23/11/2026 « Événements et participants (T-14) ».

Les décisions 10 à 14 sont inscrites dans la ligne du 23/11/2026 « Année des événements et vue entreprise (T-14) ».

## 13. Points signalés

- **RG-IMP-07 / RG-PAR-06 contre RG-PAR-04** : tranché par la décision 1.
- **Colonne `statut` de `event_companies`** : sans définition dans le CDC, non créée.
- **Événement obligatoire et T-16** : la reprise n'utilise pas ce formulaire. Elle devra rattacher les entreprises à leurs éditions passées, ce que RG-EVT-04 interdit à l'écran : la commande de reprise devra écrire ces éditions directement, avec un rapport.
- **Écart volontaire au CDC (décisions 10 et 12)** : ENT-01, ENT-02 et RG-ENT-08 prévoient un événement sélectionné, et RG-ENT-08 garde les passages accessibles après l'événement. Pour l'entreprise, seul l'événement courant reste visible. Les données ne sont ni supprimées ni modifiées. L'ancien événement reste visible tant qu'aucun nouveau n'est désigné (PC-04).
- **T-16** : si l'ancienne application contient deux événements sur une même année scolaire, la reprise sera bloquée par l'index unique. Ce cas devra être tranché avec les données réelles.
- **Fiche déjà imprimée** : si l'emplacement change ensuite, l'ancienne fiche n'est plus exacte, et le mot de passe n'étant plus lisible, on ne peut pas la réimprimer sans le réinitialiser. **Conseil d'organisation** : saisir les emplacements avant d'imprimer. Aucun changement prévu.

## 14. Risques

- **Périmètre large** (décision 4) : 6 écrans touchés. Le découpage en commits suit l'ordre base, services, API, administration, puis affichage aux participants.
- **Verrou partagé pendant un import** : une désignation attend la fin de la transaction d'import. Acceptable, car les mots de passe sont hachés hors transaction ; à vérifier à la mesure.
- **Contrat T-30** : `EventUsage` répond 0 jusque-là, donc retraits et suppressions ne sont réellement protégés qu'après T-30. Le protocole du § 6 est à reporter dans le passage de relais de `PROGRESS.md`.
- **Relais T-23, T-30, T-33 et export** : tous les écrans et exports de l'entreprise devront se limiter à l'événement courant, côté serveur (décisions 10 et 12).

## 15. Commandes de contrôle (après le code)

```bash
docker compose run --rm php sh -c "vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G --no-progress && php artisan test"
```

```bash
cd frontend && npm run lint && npm run test && npm run build
```
