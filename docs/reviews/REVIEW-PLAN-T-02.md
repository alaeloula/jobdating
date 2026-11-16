# Revue indépendante du plan T-02 — Référentiels

Date : **16/11/2026**. Branche : **`feature/T-02-referentiels`**.

Base locale : `HEAD` et `develop` pointent tous deux sur `799d49af57db4b16c2a51f144b95fb82afd34847`. La revue porte sur les modifications non commitées et le plan non suivi, pas sur une implémentation.

## 1. Documents et contrôles examinés

- `AGENTS.md`, `CLAUDE.md` et `docs/tasks/PLAN-T-02.md` (lignes 1 à 58).
- Diff complet `git diff develop` : `docs/BACKLOG.md` et `docs/DECISIONS.md`, notamment les décisions du 16 novembre et COMP-01.
- CDC V2 : matrice 3.2, ADM-09/RG-REF-01 à 03, modèle 8.2, ENT-03, ENT-05/PC-23, RG-ENTR-02, APP-01, ADM-04 et TRA-01. Les décisions validées priment sur les passages antérieurs contradictoires, conformément à la demande et au préambule de `DECISIONS.md`.
- Maquette `C:\Users\Youcode\Desktop\jobdating\maquettes\admin-12-referentiels.png`, ouverte et examinée.
- Code existant : `backend/app/Enums/Permission.php`, `Role.php`, `AuditAction.php`, `AuditCategory.php`, `backend/app/Services/AuditLogger.php`, contrôleur et tests du journal, `backend/routes/api.php`, `backend/database/seeders/DatabaseSeeder.php`, écran et tests frontend du journal. Lecture complémentaire de `docs/tasks/CURRENT_TASK.md` pour le contrat livré par T-06.
- Fichiers non suivis recensés. Le plan et le prompt de revue concernent cette demande ; les fichiers de configuration locale et `mysql.session.sql` ne sont pas des livrables T-02 examinés sur le fond.

**Contrôles locaux effectués :** branche, révisions, état Git, diff et `git diff --check develop`. Ce dernier réussit sans sortie. Aucun test applicatif, migration, analyse statique ou build exécuté : aucun développement T-02 n'est à valider et la demande exclut l'écriture de code et de tests. Aucune conclusion d'exécution sur PHP ou MySQL n'est donc fournie. Ce rapport ne vaut pas approbation d'une implémentation.

## 2. Constats, par priorité

### 1. À corriger avant développement — Collision du nom de table des étiquettes

**Références :** plan, ligne 25 ; CDC 8.2, lignes 1295–1296 ; T-31.

**Constat confirmé dans les documents :** le plan appelle le catalogue `evaluation_tags`, alors que le CDC réserve ce nom à l'association `evaluation_id, tag_id`. Le catalogue s'appelle `evaluation_tags_ref` dans le CDC, y compris dans les références du plan, ligne 9. Aucune décision validée ne change cette correspondance.

**Scénario :** T-02 crée `evaluation_tags` avec un libellé, un type et un statut. T-31 suit ensuite le modèle 8.2 pour enregistrer les étiquettes choisies dans une évaluation : le nom de sa table d'association est déjà occupé.

**Impact :** conflit de schéma à l'intégration de T-31, ou renommage tardif des tables, modèles et relations. Il s'agit d'une incohérence du plan, pas d'une migration défectueuse déjà observée.

**Correction minimale dans le plan :** garder `evaluation_tags_ref` pour le catalogue et `evaluation_tags` pour l'association future. À défaut, faire valider et documenter explicitement les deux noms alternatifs avant de développer T-02. L'URL publique du référentiel peut rester indépendante du nom SQL.

### 2. À corriger avant développement — Identification des valeurs initiales après renommage

**Références :** plan, lignes 24–25, 33, 45, 51 et 57 ; décision « Référentiels », `DECISIONS.md:30`.

**Lacune confirmée du plan ; défaut d'exécution hypothétique :** le seeder doit créer les valeurs manquantes tout en conservant les corrections et désactivations faites à l'écran. Le schéma ne prévoit cependant aucune identité stable propre aux valeurs initiales ; leurs seules clés annoncées reposent sur le libellé, ou le couple type/libellé.

**Scénario à prévoir :** lancer le seeder, renommer « Curieux », désactiver cette étiquette et modifier son ordre, puis relancer le seeder. S'il recherche la valeur par son libellé initial, il recrée « Curieux » comme une nouvelle valeur active. Ne pas écraser la ligne renommée ne suffit donc pas à respecter le comportement attendu.

**Impact :** réapparition d'une valeur retirée ou corrigée à l'écran, et catalogue qui s'enrichit à chaque correction suivie d'un déploiement.

**Correction minimale dans le plan :** choisir une identité d'initialisation immuable, distincte du libellé, du type éditable éventuel et de la position — par exemple une clé technique unique pour chaque valeur livrée. Une valeur initiale déjà identifiée n'est plus mise à jour par le seeder. Préciser aussi le traitement d'une valeur homonyme déjà saisie manuellement, sans l'écraser. La solution est faisable sans ajouter de dépendance.

**Test à inscrire explicitement :** après renommage, désactivation et réordonnancement d'une valeur initiale, une nouvelle exécution conserve son identifiant, son état, son libellé, sa position et le nombre de lignes ; l'ancien libellé n'est pas réintroduit. Un simple double lancement sur une base inchangée ne couvre pas ce scénario.

### 3. À corriger avant développement — Distinguer les options de saisie de la lecture historique

**Références :** plan, lignes 29–30, 35 et 42–43 ; RG-REF-02 ; critères d'acceptation ADM-09 ; décisions V2 sur le type final du suivi.

**Lacune confirmée du contrat ; régression future hypothétique :** la seule lecture accessible aux rôles sans `referentials.manage` renvoie les valeurs actives. Le plan exige bien que les valeurs désactivées restent lisibles, mais ne définit pas comment les futures fiches, modifications et filtres historiques les retrouveront.

**Scénario :** une opportunité est enregistrée dans une évaluation ou comme type final de suivi, puis désactivée. Un écran charge ses libellés et ses filtres uniquement depuis `/options` : la valeur enregistrée n'y figure plus. Elle peut devenir illisible ou impossible à sélectionner comme filtre historique. Une modification sans changement de cette ancienne valeur ne doit pas non plus l'effacer implicitement.

**Impact :** non-respect de l'affichage des données existantes et filtres historiques incomplets. Ce scénario ne prouve pas un bug actuel : les écrans consommateurs sont justement hors périmètre T-02.

**Correction minimale dans le plan :** définir trois usages : options actives pour les nouvelles affectations ; restitution de la valeur déjà liée dans les ressources autorisées, même inactive ; valeurs nécessaires aux filtres historiques selon le périmètre de l'écran. Cela n'impose pas d'ouvrir la gestion ni toutes les données historiques à tous les comptes. Reporter les vérifications d'affichage et de conservation aux tâches consommatrices, avec ce contrat explicite dès T-02.

### 4. Suggestion — Détailler la vérification de l'intégration au journal T-06

**Références :** plan, lignes 32, 44 et 51 ; TRA-01 ; `backend/app/Enums/AuditAction.php:28`, `backend/app/Services/AuditLogger.php:61`, `backend/app/Http/Controllers/AuditLogController.php:53`, `backend/tests/Feature/Audit/AuditLogApiTest.php:129`.

**Risque hypothétique lié au code existant :** ajouter des actions ne suffit pas à les classer correctement : `AuditAction::category()` renvoie actuellement « Comptes » par défaut. Le libellé du sujet n'est recopié que pour les comptes `User`. Le test du filtre attend exactement six catégories.

**Scénario :** une activation n'enregistre que `active: false → true`, sans libellé du référentiel, ou une nouvelle action garde la catégorie par défaut. L'entrée existe mais son résumé est peu identifiable ou elle n'apparaît pas dans le filtre « Référentiels ».

**Impact :** journal moins exploitable, malgré un test vérifiant seulement qu'une ligne a été créée.

**Ajout proposé dans le plan :** vérifier la correspondance actions/catégorie, l'identification lisible du sujet, les valeurs avant/après et la consultation via le filtre « Référentiels ». Le filtre frontend consomme déjà les catégories retournées par l'API : aucune liste parallèle n'est nécessaire. Prévoir l'adaptation du test à six catégories et l'atomicité entre mutation et écriture d'audit, y compris pour le réordonnancement. Si le seeder journalise ses créations, conserver l'auteur « Système » et ne produire aucune nouvelle entrée lors d'une relance sans changement.

### 5. Suggestion — Préciser les invariants derrière les tests génériques

**Références :** plan, lignes 19, 31, 40–43 et 51 ; ADM-09 ; décision « Référentiels ».

**Constat documentaire :** « validation », « ordre » et « filière d'une classe » sont des intentions de tests, sans détailler certains cas qui pourraient laisser passer une implémentation incorrecte.

**Scénarios et impact :** un code de filière dit stable peut être modifié par le PATCH générique ; un ordre contenant un identifiant étranger au groupe peut déplacer une autre liste ; un point d'extension de contrôle d'utilisation peut ne jamais être appelé. Les tests nominaux annoncés pourraient rester verts.

**Ajouts proposés dans le plan, sans étendre le métier :**

- Expliciter l'immutabilité du code de filière après création et la tester, distinctement de la correction du libellé.
- Définir l'ordre des étiquettes par type et celui des opportunités ; valider les identifiants, doublons et mises à jour partielles du réordonnancement ; garantir une opération atomique et un tri déterministe.
- Prévoir une clé classe → filière valide et le refus d'une filière inexistante. Le point d'extension doit pouvoir simuler « classe utilisée » et faire refuser la modification sans attendre la table `learners` ; T-12 remplacera cette simulation par la vérification réelle.
- Décliner les droits sur chaque opération de gestion, dont le changement d'état et l'ordre, et pas seulement sur le GET. Vérifier qu'un type ou identifiant manipulé ne permet pas de modifier un autre référentiel.

## 3. Questions au responsable technique

1. **Classe déjà utilisée :** « figée dès qu'elle est utilisée » signifie-t-il définitivement après la première affectation, même si tous ses apprenants changent ensuite de classe ? Le plan dit « tant que […] aucun apprenant », ce qui pourrait être compris comme un simple état courant. Confirmer cette interprétation pour T-12 ; cette tâche devra aussi rendre le contrôle cohérent avec les créations et changements de classe concurrents, puis avec l'import T-13.
2. **Compétence désactivée :** ses associations existantes restent conservées. Doivent-elles encore participer aux suggestions de T-40, ou seule une compétence active doit-elle permettre un rapprochement ? La décision impose le « filière OU compétence », mais ne tranche pas ce cas. COMP-01 porte sur la saisie en masse et ne répond pas à cette question.
3. **Étiquette déjà utilisée :** son type « point fort / à améliorer » peut-il être corrigé, au risque de déplacer l'étiquette dans les évaluations existantes, ou faut-il créer une nouvelle valeur et désactiver l'ancienne ? La correction du libellé est autorisée ; le changement de type n'est pas défini.

Ces questions ne remettent pas en cause les décisions validées. Les deux premières concernent surtout les contrats à reprendre dans les tâches suivantes.

## 4. Points validés sans remarque

- **Périmètre fonctionnel :** les sept référentiels d'ADM-09 et le nouveau catalogue des compétences sont couverts. Les champs du modèle 8.2 sont repris, avec la relation classe → filière, le code de filière, le statut actif et la position des opportunités/étiquettes. Les unicités annoncées sont cohérentes avec des listes administrables.
- **Nom `school_classes` :** acceptable si la correspondance avec `classes` est reprise dans T-12/T-13. La justification ligne 56 est à rectifier : le mot-clé PHP est `class` ; le nom d'une table SQL `classes` n'impose pas de classe PHP nommée `Class`. Ce choix de nom ne constitue pas un défaut fonctionnel.
- **Droits :** `referentials.manage` existe et est attribuée à l'administrateur et à la CME. La lecture des options de catalogue par tout connecté est cohérente avec les futurs formulaires et filtres. Elle ne donne ni accès aux évaluations ni aux associations individuelles de compétences. Limiter sa réponse aux champs utiles aux options ; l'e-mail de contact d'un campus n'est pas nécessaire pour choisir un campus. Conserver les contrôles de session existants.
- **Compétences :** la contradiction avec ENT-03/PC-15 est volontaire et validée. Le catalogue T-02 ne doit pas créer prématurément `learner_competence` ni des niveaux. T-12 portera les associations sans niveau et la nouvelle permission réservée à l'administrateur et à la CME ; cette extension ciblée n'autorise pas la CME à modifier tous les autres champs d'un apprenant. T-11 reprend les compétences recherchées avec le même avertissement que les filières. T-40 reprend bien la participation à l'événement et la condition filière **ou** compétence. Aucun conflit nouveau entre les décisions et le backlog n'a été relevé.
- **COMP-01 :** ne bloque pas la création du catalogue. La saisie en masse et une éventuelle source CME restent à arbitrer avant les tâches qui renseigneront et exploiteront les compétences, sans importer de données ni ajouter de fonctionnalité dans T-02.
- **Absence de suppression :** conforme à la décision plus stricte que RG-REF-02. Désactivation réversible et conservation des références existantes sont les bons principes.
- **Classe non encore utilisée :** le point d'extension est une approche saine avant T-12, à condition de ne pas interroger une table absente et de rendre son branchement obligatoire dans T-12. Ne pas introduire maintenant une table d'apprenants factice.
- **Données initiales :** les cinq opportunités et huit étiquettes énumérées sont fidèles aux libellés ENT-05/PC-23, dont « Stage de pré-embauche ». Les six autres listes restent vides. Leur initialisation via le `DatabaseSeeder` de production est cohérente ; aucune création de compte n'est prévue.
- **Maquette :** onglets, champs des classes, filière associée, statut, ajout, modification et note de conservation sont repris. L'onglet supplémentaire « Compétences » découle de la décision du 16 novembre.
- **Découpage des tâches :** les formulaires et filtres consommateurs peuvent attendre T-11/T-12/T-13/T-31/T-40. Le critère global « une filière ajoutée apparaît partout » ne sera toutefois vérifiable qu'à leur livraison : T-02 prépare la source dynamique, il ne clôt pas seul cette acceptation. T-13 garde l'ajout d'une classe inconnue depuis l'aperçu d'import, en réutilisant les règles T-02. T-31 réutilisera les opportunités et étiquettes ; le suivi V2 utilisera les mêmes opportunités pour le type final, sans liste dupliquée.
- **Architecture :** API `/api/v1`, droits serveur, tests backend et Vitest, réutilisation d'`AuditLogger`. Aucune nouvelle dépendance ni fonctionnalité étrangère à T-02 n'est annoncée.

## 5. Verdict

**Plan à revoir.**

Corriger avant développement la collision de table, l'identité des valeurs initiales et le contrat de lecture des valeurs désactivées. Les décisions métier validées et le découpage général restent cohérents. Une implémentation ultérieure nécessitera sa propre revue et la communication de résultats de tests locaux, notamment sur MySQL pour les contraintes et la concurrence.
