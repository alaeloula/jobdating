# Revue du plan T-14 — Événements, participants et emplacements

- **Date :** 23/11/2026.
- **Plan :** `docs/tasks/PLAN-T-14.md`, version 1.1, et `docs/tasks/CURRENT_TASK.md`.
- **État examiné :** branche `develop`, HEAD `1b05242254e94e4843dd414d22de80621cec6f29` ; changements documentaires non commités et fichiers non suivis pertinents. La branche `feature/T-14-evenements` reste à créer avant le développement.
- **Nature :** revue documentaire avant développement. Les scénarios ci-dessous sont des scénarios à tester après implémentation, pas des bugs T-14 reproduits sur une application existante.

## Références et périmètre

Lecture du prompt, d’`AGENTS.md`, de `CLAUDE.md`, du plan complet, du diff de `DECISIONS.md` et de `CURRENT_TASK.md`. Comparaison avec le CDC V2 : ADM-05, ADM-07, RG-ENTR-03, RG-IMP-06/07, ENT-01, APP-00/01, matrice 3.2, modèle 8.2, §8.8 et TRA-01. Les décisions du 23/11, notamment les décisions 1 à 9 du plan, sont appliquées sans les remettre en cause.

Les passages de relais T-11 à T-13 dans `PROGRESS.md` et les erreurs reportées E-01 à E-06 ont été consultés. Ces dernières ne sont pas rouvertes.

Code existant consulté comme référence : services apprenants, import et entreprises, contrôleur entreprises, `ClassUsage`, audit, permissions, accès aux liens de mot de passe, routes, données de démonstration ; routes et navigation React, pages entreprises/apprenants, formulaire et fiche de connexion. Les sept maquettes demandées ont été examinées : `admin-02`, `admin-03`, `admin-04`, `admin-05`, `cme-02`, `entreprise-ordi-01`, `apprenant-01`.

Exclusions respectées : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`. Aucun fichier réel d’apprenants utilisé, aucun accès au dossier Téléchargements. Seul ce rapport est ajouté ; aucun code, plan ou autre document n’est modifié.

## Constats

### 1. Majeur — La stratégie d’inscription en masse ne couvre pas tous les chemins de verrouillage existants

**Référence :** plan §6, lignes 97–101, §10, ligne 147 ; `backend/app/Services/Learners/LearnerService.php:103` et `:434`.

**Nature :** risque hypothétique d’interblocage, lié au choix SQL annoncé ; aucune concurrence T-14 exécutée.

**Problème :** la barrière partagée est prévue pour créer, réactiver, restaurer et importer. Les modifications ordinaires et l’ajout de compétences en masse restent susceptibles de verrouiller les comptes puis les fiches pendant la désignation. Or l’`INSERT … SELECT` annoncé peut lui-même verrouiller les lignes sources : sous l’isolation `REPEATABLE READ`, MySQL prend des verrous partagés sur les sources de cette instruction. Le verrou exclusif sur `event_locks` ne protège donc pas contre les chemins qui ne prennent pas cette barrière. Le comportement SQL est documenté par [MySQL 8.0](https://dev.mysql.com/doc/refman/8.0/en/innodb-locks-set.html).

**Scénario à contrôler :** une modification d’apprenant détient le verrou du compte et doit verrouiller sa fiche. Si le plan d’exécution de l’inscription en masse verrouille d’abord cette fiche puis attend le compte, les deux transactions s’attendent. L’ordre réel dépendra de la requête, des index et de l’isolation : ce cycle n’est pas présenté comme systématique. Les deux essais prévus, création/import contre désignation, ne couvrent pas ce cas.

**Impact :** annulation d’une désignation ou d’une modification légitime, éventuellement erreur serveur sans reprise adaptée. Les futurs services qui réutiliseront cette inscription hériteraient du problème.

**Correction minimale dans le plan :** préciser comment la lecture des apprenants pour l’inscription en masse respecte l’ordre des verrous existants. Une possibilité est une lecture cohérente non verrouillante des identifiants, puis une insertion des participations, en justifiant la fraîcheur de cette lecture sous la barrière ; une autre est d’étendre un protocole de verrouillage commun aux chemins concernés. Ne pas imposer arbitrairement une refonte de tous les services. Préciser la reprise de la transaction entière en cas d’interblocage, sans doubler le journal ou les effets différés. Ajouter des essais MySQL à deux connexions avec `update`, `bulkAddCompetence` et `bulkDeactivate`, en plus de ceux déjà prévus.

**Fréquence et suites :** concurrence probablement occasionnelle, fréquence non mesurée ; impact sur T-12/T-13 et sur la convention de concurrence des tâches suivantes. Ce n’est pas un défaut rare démontré sans conséquence ultérieure.

### 2. Majeur — L’événement sélectionné par l’entreprise n’a pas de contrat

**Référence :** plan §5, lignes 85–87, §7, ligne 115, §9, ligne 131 ; CDC ENT-01, ligne 587, et RG-PAR-07.

**Nature :** lacune confirmée du plan ; le mauvais affichage serait un risque d’implémentation.

**Problème :** une entreprise peut participer à plusieurs éditions. ENT-01 parle d’un événement sélectionné, avec une valeur par défaut. Le plan annonce une route singulière `/me/company-event` et l’affichage de « sa participation », sans choix d’événement, liste des participations ni règle lorsqu’elle ne participe pas à l’événement courant. La sélection administrative qui alimente la fiche de connexion n’est pas davantage reliée à ce contrat.

**Scénario :** E1 est courant ; une nouvelle entreprise est créée légalement dans E2, futur et non courant. Elle se connecte. Un comportement fondé exclusivement sur E1 afficherait « sans participation » alors qu’elle est inscrite à E2. Autre cas : une entreprise possède deux participations avec des emplacements différents ; le plan ne permet pas de déterminer laquelle afficher ou imprimer après une réinitialisation du mot de passe.

**Impact :** événement ou emplacement absent ou erroné ; choix d’API à reprendre lorsque T-23, T-30 et T-33 utiliseront l’événement sélectionné.

**Correction minimale dans le plan :** définir la sélection parmi les seules participations de l’entreprise, son défaut et le cas où le courant n’en fait pas partie ; prévoir le contrat API nécessaire et le contrôle serveur d’un identifiant d’événement fourni. Transmettre explicitement le contexte de participation à la fiche, y compris après réinitialisation. Ajouter les tests « deux éditions, deux emplacements », « participation uniquement à un événement futur non courant », « aucune participation » et « événement d’une autre entreprise refusé ». Cela ne demande pas les compteurs, passages ou programmes reportés.

**Fréquence et suites :** cas normal dès la deuxième édition, ou dès une création sur un événement futur non courant ; impact direct sur les tâches consommatrices. Non reportable comme défaut rare sans impact.

### 3. Majeur — La transaction de création entreprise + participation reste à définir

**Référence :** plan §5, ligne 85, §6, ligne 100, §10, ligne 144 ; `backend/app/Services/Companies/CompanyService.php:55` et `:345` ; RG-ENTR-03 et maquette `admin-05`.

**Nature :** lacune confirmée du plan ; risque hypothétique de création partielle, pas un nouveau bug reproduit dans T-11.

**Problème :** l’objectif « rien n’est créé, logo compris » est bien annoncé pour les événements invalides. Mais le plan ne précise pas la frontière transactionnelle qui réunira compte, entreprise, participation et audit. Le service existant écrit le logo avant sa propre transaction et possède un nettoyage adapté à des conditions d’imbrication documentées. Appeler successivement ce service puis le service de participation ne suffit pas à assurer RG-ENTR-03.

**Scénario :** le formulaire reçoit un événement valide, le service crée l’entreprise, puis l’insertion de la participation ou son audit échoue. Sans transaction englobante correcte, l’entreprise reste créée sans participation. Un événement peut aussi être archivé ou supprimé entre la validation initiale et l’affectation si son état n’est pas relu sous le verrou approprié.

**Impact :** compte exploitable mais absent de l’événement, logo orphelin ou journal incomplet. Le risque se situe sur le nouveau parcours nominal de création.

**Correction minimale dans le plan :** choisir une transaction propriétaire unique pour la création complète, relire l’événement sous verrou et contrôler son éligibilité dans cette transaction. Décrire le nettoyage du nouveau logo si la participation ou l’audit échoue ; éviter d’introduire les deux niveaux englobants dont la limite est déjà documentée dans T-11. Ajouter les tests d’échec après création de la fiche mais avant validation finale, et de concurrence avec archivage/suppression : ni compte, ni entreprise, ni participation, ni audit partiel, ni nouveau logo résiduel. Le cas ancien de nettoyage n’est pas rouvert indépendamment de cette nouvelle composition.

**Fréquence et suites :** échec après validation probablement rare, mais intégrité structurante pour les futures créations/importations d’entreprises. Pas sans impact sur les tâches suivantes.

### 4. Mineur — Le relais à T-30 doit préciser le périmètre du comptage et la revalidation des participants

**Référence :** plan §4, ligne 70, §6, ligne 100, §14, ligne 176 ; CDC RG-PAR-04 et RG-EVT-03.

**Nature :** contrat documentaire incomplet ; risques futurs hypothétiques.

**Problème :** « nombre de passages d’une entreprise ou d’un apprenant » doit obligatoirement s’entendre **dans l’événement concerné**. Par ailleurs, demander à T-30 de prendre le même verrou avant une création ne dit pas qu’il doit y relire et vérifier la participation après avoir obtenu ce verrou.

**Scénarios :** un passage de l’entreprise dans E1 ne doit pas interdire son retrait de E2. En concurrence, un retrait peut terminer avant qu’une création de passage en attente obtienne le verrou ; si cette création utilise une participation vérifiée avant l’attente, le verrou seul n’empêche pas un passage après retrait.

**Impact :** refus indus entre éditions ou participant retiré possédant ensuite un passage.

**Correction minimale dans le plan :** expliciter les opérations de comptage par événement et par couple événement/participant, sans exiger d’évaluation et sans dédupliquer les rencontres. Écrire le relais T-30 : verrou de l’événement, relecture des participations et de leur éligibilité, puis création dans la même transaction. Le protocole doit également encadrer les contrôles précédant suppression ou modification de date. Ajouter dès T-14 des doubles de `EventUsage` distinguant E1/E2 et inscrire les essais retrait/création dans les obligations T-30. L’implémentation provisoire à zéro reste acceptable avant l’existence des passages.

**Fréquence et suites :** pas de bug de passages exécutable aujourd’hui ; incidence directe sur T-30. Une précision du plan suffit, mais ce relais ne doit pas disparaître dans T-49.

### 5. Suggestion — Documenter le parcours des données de démonstration après désignation du courant

**Référence :** plan §5, lignes 79 et 86, §11, ligne 156 ; `backend/database/seeders/DemoAccountsSeeder.php:64` ; relais T-11/T-12 dans `PROGRESS.md`.

**Nature :** cas non décrit dans le plan, comportement actuel du seeder confirmé par lecture.

**Scénario :** le courant existe déjà, puis le seeder local crée la fiche de l’apprenant de démonstration ou réactive son compte directement. Il n’appelle pas les chemins de `LearnerService` auxquels l’inscription sera ajoutée. À l’inverse, l’entreprise de démonstration sans participation peut correctement être ajoutée par l’administrateur selon le §5.

**Impact :** recette locale trompeuse : apprenant de démonstration actif mais écran vide. Aucun impact de production de ce seeder, réservé à l’environnement local.

**Correction proposée dans le plan :** choisir et documenter un parcours local reproductible. Si le seeder est adapté, réutiliser l’inscription idempotente sans rétablir un retrait explicite ; sinon expliquer l’ordre de préparation et le comportement attendu à la relance. Ne pas inscrire automatiquement les anciennes entreprises, contrairement à la décision validée.

**Fréquence et suites :** limitée à la préparation locale et à ses tests ; peut être traitée comme amélioration de recette, sans blocage métier des tâches suivantes.

## Points vérifiés sans remarque

- **ADM-05 :** champs attendus, rappel, statut calculé sur des journées entières à Casablanca, archivage réversible et refus de suppression avec passages. La borne de date est le jour de création de l’événement, y compris en modification ; elle n’est pas remplacée à tort par la date du jour de modification.
- **Unicité du courant :** le marqueur généré à `1` ou `NULL` et son index unique sont adaptés à « au plus un ». MySQL autorise plusieurs valeurs `NULL` dans un index unique ; les événements non courants peuvent donc coexister. [Documentation MySQL 8.0](https://dev.mysql.com/doc/refman/8.0/en/create-index.html).
- **Décisions :** un événement terminé peut rester courant mais ne peut pas être nouvellement désigné ; le statut courant peut être retiré. L’archivage du courant est refusé. La lecture seule d’un événement archivé couvre également les mutations de ses participants et emplacements.
- **Participants :** unicité par événement, absence de capacité, emplacement facultatif, conservation des éditions antérieures et absence de reprise automatique des entreprises. Le marqueur `removed_at` permet de conserver un retrait lors d’une désignation répétée, d’un import ou d’une réactivation.
- **Apprenants actifs :** les chemins métier création/import/réactivation/restauration sont identifiés. Une restauration ne doit inscrire que si le compte est effectivement actif, comme l’exige le §5. L’activation par lien ne concerne pas les apprenants dans le code actuel (`PasswordAccess`) ; ajouter un tel parcours n’est pas nécessaire pour T-14. Le critère « activation le jour J » doit être vérifié sur les parcours actifs réellement disponibles ; la recherche entreprise elle-même appartient à T-23.
- **Import inchangé :** le runner verrouille aussi ces fiches mais saute leurs écritures. Ce saut ne prouve pas une violation de RG-IMP-07, qui vise les créations/mises à jour : sous l’invariant annoncé, l’actif est déjà inscrit ou explicitement retiré. Prévoir un test conservant respectivement ces deux états, sans changement de mot de passe ni notification supplémentaire. Le cas particulier du seeder est traité au constat 5.
- **Droits :** les permissions existantes suffisent : administration des événements/participants pour l’administrateur, emplacements pour l’administrateur et la CME. Les routes personnelles sont distinctes des routes de gestion. La vérification serveur par participation et les tests sur les cinq rôles sont correctement annoncés.
- **Confidentialité :** la réponse apprenant est limitée aux données d’événement et aux entreprises visibles, sans contact, identifiant de connexion ni évaluation. La désactivation masque l’entreprise aux apprenants tout en la conservant pour l’administrateur et la CME.
- **Audit :** actions identifiées et inscription massive résumée en une ligne de comptes, sans CNIE. Les mutations et leur audit devront partager la transaction, conformément aux pratiques existantes.
- **Frontend et découpage :** écrans et responsabilités cohérents avec les maquettes pour la partie retenue. Chargement, nouvel essai, neutralisation des réponses tardives et double clic sont prévus. Les tests devront exercer réellement « erreur → correction → nouvel essai », notamment un changement d’événement pendant une requête. Le programme, le CV, les passages et les compteurs restent explicitement reportés ; aucune dépendance nouvelle n’est nécessaire.

## Questions au responsable technique

1. **Choix initial de l’événement entreprise :** lorsqu’elle ne participe pas au courant mais possède une ou plusieurs autres participations, quelle édition faut-il sélectionner par défaut ? Le besoin de sélection est établi par ENT-01 ; sa règle de repli n’est pas tranchée par les décisions. Cette réponse complétera le constat 2.
2. **« Fiche non imprimée » à la deuxième édition :** l’état signifie-t-il « version actuelle des identifiants déjà imprimée, toutes éditions confondues » ou « fiche de cette participation imprimée » ? Le code T-11 stocke `credentials_printed_at` sur l’entreprise, alors que la nouvelle fiche contient un événement et un emplacement. Préciser ce sens dans le plan et dans le filtre. Cela ne remet pas en cause le choix du §13 concernant un emplacement modifié après impression, ni ne demande de conserver le mot de passe en clair.

## Validation et limites

Contrôles effectués : lecture des documents, du diff documentaire et des fichiers non suivis pertinents ; inspection statique du code existant ; comparaison visuelle des sept maquettes ; vérification des deux propriétés MySQL citées dans sa documentation officielle.

**Aucun test applicatif, lint, build, migration ou banc de concurrence n’a été exécuté.** Il n’existe pas encore de code T-14 à tester. Les commandes du §15 sont des contrôles futurs, pas des résultats acquis. Aucun verrou n’a été validé expérimentalement sur MySQL et aucune reproduction SQLite n’est invoquée. Cette revue du plan n’approuve pas une implémentation ; la revue du code devra disposer de résultats locaux, conformément à `AGENTS.md`.

La suppression de la participation entreprise fournit la base du critère « absente des statistiques ». Sa vérification complète restera à faire dans les tâches statistiques/exports, en utilisant le même périmètre de participation ; aucun calcul statistique supplémentaire n’est demandé dans T-14.

## Verdict

**Plan à revoir.**

Les décisions métier et l’essentiel du périmètre sont cohérents. Avant développement, préciser le protocole de concurrence de l’inscription massive, le choix d’événement côté entreprise et la transaction de création entreprise/participation/logo. Compléter également le contrat transmis à T-30. Ces corrections restent dans le plan, sans élargissement aux fonctionnalités reportées.
