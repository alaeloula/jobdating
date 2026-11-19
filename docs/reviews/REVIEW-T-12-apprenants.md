# Revue du code — T-12 (JD-17), apprenants

- **Date :** 19/11/2026.
- **Branche :** `feature/T-12-apprenants`, modifications non commitées.
- **Base :** `develop`, `d6cb56f66417bd882ef521f55c897347411768a7`.
- **Contrat :** `docs/tasks/PLAN-T-12.md`, version 2.1.
- **Verdict : À corriger avant fusion.** Deux défauts majeurs confirmés dans les actions en masse, ainsi que trois défauts mineurs.

## Références et périmètre

Revue de `git diff develop` et des fichiers non suivis de T-12 : migration, modèle et ressource apprenant, contrôleur, services et filtres, normalisation CNIE, permissions, audit, notification, branchement `ClassUsage`, seeder, routes, composants et tests React. Lecture des consignes `AGENTS.md` et `CLAUDE.md`.

Références : plan v2.1 et deux rapports de revue du plan ; décisions du 14/11, du 16/11 et du 19/11 ; CDC V2 ADM-03, RG-APR-01 à 07, matrice 3.2, modèle 8.2, TRA-01, EXI-DATA-03, PC-15 et PC-22 ; contrat T-02 sur les classes et les référentiels inactifs ; maquettes admin-06/admin-09 déjà examinées pendant la revue du plan ; entrée T-12 de `PROGRESS.md`, notamment ses limites annoncées.

Les décisions validées et les reports vers T-13, T-14, T-20 à T-24, T-40 et T-61 sont respectés comme périmètre de référence. E-01 à E-04 ne sont pas rouverts. Exclusions respectées : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.

## Constats

### 1. Majeur — Une requête sans sélection peut désactiver tous les apprenants éligibles

- **Fichier et lignes :** `backend/app/Http/Controllers/LearnerController.php:236`, notamment `:247` ; transformation de la sélection dans ce même contrôleur et `LearnerService::selection()`.
- **Nature :** bug confirmé par des requêtes HTTP de test sur MySQL 8, dans des tables préfixées isolées.
- **Scénario reproductible :** avec deux apprenants actifs, envoyer `POST /api/v1/learners/selection/count` avec uniquement `{"action":"deactivate"}`. Résultat obtenu : **200**, `selected: 2`, `eligible: 2`. Envoyer ensuite `POST /api/v1/learners/bulk-deactivate` avec uniquement `{"expected_eligible":2}`. Résultat obtenu : **200**, `changed: 2` ; les deux comptes sont désactivés. Aucun `ids` ni `filters` n’a été fourni.
- **Cause :** le contrôle « au moins une des deux clés » est placé dans une règle non implicite sur un attribut artificiel `selection`, absent de la requête. Laravel n’exécute pas cette règle dans ce cas. Le contrôleur construit alors des filtres vides et le service les interprète comme la sélection globale.
- **Impact :** une requête incomplète devient une opération globale, contrairement au contrat imposant une sélection explicite. Le droit administrateur reste nécessaire ; ce n’est pas un contournement de rôle. Le même défaut de validation concerne les autres endpoints utilisant `selectionRules()`, dont l’ajout de compétence en masse. Une synthèse peut en outre décrire cette sélection vide comme des « apprenants cochés ».
- **Correctif minimal proposé :** vérifier systématiquement la présence d’exactement une des clés `ids`/`filters`, avant la construction de la sélection ou dans un contrôle de validation toujours exécuté. Conserver la distinction entre absence des deux clés, à refuser, et `filters: {}`, sélection globale explicitement demandée. Ajouter le cas sans aucune clé sur le comptage et les deux exécutions, en vérifiant l’absence d’écriture et d’audit.
- **Fréquence et suite du projet :** requête mal formée, sans condition de concurrence ; concerne le contrat central des actions en masse. À corriger avant fusion.

### 2. Majeur — L’ajout de compétence en masse ne verrouille pas les comptes et peut modifier une fiche supprimée entre-temps

- **Fichier et lignes :** `backend/app/Services/Learners/LearnerService.php:367`, notamment `:375` à `:377`, puis la boucle d’association.
- **Nature :** bug confirmé avec deux connexions MySQL indépendantes.
- **Scénario reproductible :** lancer un ajout de compétence sur un apprenant actif avec un éligible attendu. Après le `pluck()` des identifiants éligibles et avant le verrouillage des fiches, une seconde connexion valide la suppression logique de son compte. L’exécution reprend : résultat obtenu **`changed: 1`**, compte effectivement supprimé, compétence néanmoins attachée. Le nombre confirmé n’est pas réévalué à zéro.
- **Cause :** le calcul initial joint `users`, mais la requête verrouillée suivante ne porte que sur `learners`. Le chargement `with('user')` n’est pas un verrou du compte ni une nouvelle vérification de son éligibilité. Le code déroge donc à l’ordre compte → fiche et à la relecture du compte prévus au plan.
- **Impact :** modification d’un apprenant devenu inéligible et confirmation devenue périmée acceptée. Cette méthode est aussi utilisée par la CME, pour laquelle un apprenant supprimé est invisible. Le refus prévu pour un identifiant déjà supprimé au début de la requête ne couvre pas cette course.
- **Correctif minimal proposé :** verrouiller les comptes sélectionnés dans un ordre stable avant leurs fiches, puis déterminer les éligibles sur l’état relu sous ces verrous et comparer ce nombre avant toute association. Garder un ordre de verrous compatible avec les modifications individuelles de compétences. Ajouter une régression à deux connexions : suppression validée avant le contrôle final → aucun ajout ; avec un attendu de un et zéro éligible, le contrat prévoit un 409.
- **Fréquence et suite du projet :** course rare, mais elle touche l’invariant d’éligibilité et le protocole de concurrence, pas seulement l’affichage. Son report ne doit pas être assimilé automatiquement aux défauts sans impact métier de T-49.

### 3. Mineur — Une réponse de comptage tardive rétablit une confirmation annulée

- **Fichier et lignes :** `frontend/src/pages/LearnersPage.tsx:125`, notamment `:134`, fermeture du dialogue `:318`, sélection de page `:345`.
- **Nature :** bug confirmé dans un banc DOM avec le composant réel et une réponse API différée.
- **Scénario reproductible :** sélectionner une page, ouvrir « Désactiver la sélection », retarder le comptage, puis cliquer « Annuler ». Le dialogue disparaît. Libérer ensuite la réponse : le dialogue **se rouvre**, avec « Confirmer » actif. Variante contrôlée : changer de filtre pendant le comptage, sélectionner la nouvelle page via sa case d’en-tête, puis libérer l’ancien comptage. L’ancienne confirmation réapparaît ; sa confirmation envoie les nouveaux identifiants `[3,4]` avec l’ancien nombre attendu `2`, sans comptage de cette nouvelle sélection.
- **Impact :** annulation et remise à zéro de sélection contredites par une ancienne réponse. Le comptage peut être présenté pour un ensemble différent. Aucune exécution automatique n’a été observée : un clic sur « Confirmer » reste nécessaire et les droits serveur restent appliqués.
- **Correctif minimal proposé :** invalider les réponses de comptage dès l’annulation, un changement de sélection/filtre/page ou une nouvelle demande. N’appliquer le résultat que s’il correspond encore à la sélection et à l’action courantes. Couvrir réponse tardive après annulation, après changement de filtre et réponses arrivant dans l’ordre inverse.
- **Fréquence et suite du projet :** occasionnel sur réseau lent. Pas de dépendance technique bloquante identifiée pour les tâches suivantes ; un éventuel report nécessite une décision explicite, compte tenu du contexte d’action en masse.

### 4. Mineur — Une recherche en erreur est affichée comme une liste vide

- **Fichier et lignes :** `frontend/src/pages/LearnersPage.tsx:59`, `:359` et `:366`.
- **Nature :** bug confirmé dans le banc DOM, réponse de recherche simulée en erreur 503.
- **Scénario reproductible :** faire échouer la recherche initiale jusqu’à l’état d’erreur définitif de la requête. Résultat obtenu : texte **« Aucun apprenant. »**, sans alerte. Le rendu traite `rows.length === 0` comme un succès vide et ne présente pas `list.isError`.
- **Impact :** l’utilisateur peut conclure à tort qu’aucune fiche ne correspond à sa recherche. Il ne dispose pas d’un message expliquant l’échec ni d’un nouvel essai explicite sur cet état.
- **Correctif minimal proposé :** distinguer chargement, erreur et succès vide ; afficher une erreur compréhensible avec un moyen de relancer la recherche. Tester échec → nouvel essai → résultats, ainsi que la disparition de l’erreur après succès.
- **Fréquence et suite du projet :** conditionné par une panne réseau/serveur ; affichage seulement, sans modification des données. Aucun blocage de développement ultérieur identifié ; report possible uniquement sur décision du responsable technique.

### 5. Mineur — Le traitement HTTP retire encore des caractères interdits de la CNIE

- **Fichier et lignes :** `backend/app/Http/Controllers/LearnerController.php:68` et `:273`, `backend/app/Support/Cnie.php:14` et `:18` ; chaîne de middleware configurée dans `backend/bootstrap/app.php`.
- **Nature :** écart de contrat confirmé, déjà signalé comme limite dans `PROGRESS.md`, sans décision écrite l’autorisant.
- **Scénario reproductible :** envoyer une CNIE fictive valide suivie de LF, puis une autre suivie d’une tabulation. Résultat API obtenu : **201 dans les deux cas**. Le middleware `TrimStrings` retire ces caractères avant que `Cnie` reçoive la valeur. Le helper, lui, utilise correctement l’ancrage strict et refuse une valeur brute avec LF.
- **Impact :** la décision 9 (« seuls les espaces sont retirés ») et le contrat v2.1 ne sont pas respectés par l’API. Une même entrée peut être acceptée par HTTP et refusée par le helper utilisé directement lors du futur import. Le fait que ce soit le comportement global de Laravel n’en fait pas une exception métier validée.
- **Correctif minimal proposé :** exclure le champ `cnie` du nettoyage global qui retire ces caractères, puis laisser le helper appliquer uniquement la normalisation autorisée. Conserver le traitement des autres champs. Tester LF et tabulation par l’API, en plus du helper ; autre possibilité seulement après décision écrite : modifier le contrat pour accepter cette normalisation aux deux points d’entrée.
- **Fréquence et suite du projet :** cas de saisie/collage particulier, mais contrat partagé avec T-13 ; ne pas le classer comme un défaut sans incidence sur les tâches suivantes.

## Vérification du contrat [v2] et [v2.1]

| Point | Résultat dans le code |
|---|---|
| Règle CNIE commune, chaîne et zéros initiaux | `Cnie` centralisé, ancrage `\A…\z` correct. **Écart HTTP : constat 5.** |
| CNIE masquée en liste, complète et journalisée à l’ouverture administrateur | `LearnerResource` et `show()` respectent cette séparation. Absence de CNIE/téléphone pour CME et Direction vérifiée par les tests ciblés. |
| Recherche POST, aucun texte libre dans les synthèses | Recherche et cache distinguent filtres/page ; `LearnerFilters::describe()` ne conserve que des libellés et « recherche : oui ». |
| Collisions SQL sans CNIE dans l’erreur | **Rejoué pour création et modification** : insertion concurrente de la même CNIE sur une autre classe, puis 422 sur `cnie`, sans la valeur dans la réponse. Le chemin `LearnerService::unique()` a bien été exercé. |
| `LearnerClassUsage`, toutes les fiches et branchement | Implémentation réelle branchée, compteur direct des lignes conservées ; tests d’utilisation de classe avec désactivés/supprimés réussis. |
| Classe → compte → fiche et reprise si classe déplacée | Ordre présent dans création/modification. Banc à deux connexions : déplacement validé entre la première lecture et le verrou, puis reprise réussie avec deux lectures de classe. **Exception dans l’ajout de compétence en masse : constat 2.** |
| Verrou de classe effectif sur MySQL | Pendant un vrai appel `create()`, une seconde connexion essayant de modifier la classe attend puis atteint son délai d’attente de verrou. Cela démontre le verrou dans le scénario contrôlé. |
| Révocation sur changement réel d’e-mail | Sous verrou du compte ; adresse inchangée sans révocation. Tests ciblés réussis. |
| Notification après validation finale, deux motifs | `afterCommit`, tâche chiffrée et textes distincts conservés. Test d’annulation englobante avec vraie file `database`, plutôt qu’avec le seul faux de notification. |
| Sélection, visibilité et éligibilité par action | Règles distinctes correctes en régime non concurrent ; supprimé invisible pour CME, ignoré pour admin. **Absence de sélection : constat 1 ; course d’éligibilité : constat 2.** |
| Action déterminée par la route | Les méthodes d’exécution choisissent leur action et ne lisent pas le champ client `action`. Le comptage refuse la désactivation demandée par une CME. |
| 0 attendu / 0 trouvé | `confirmCount()` retourne sans écriture ni synthèse ; différence de nombre → 409. |
| Référentiels inactifs | Valeurs liées avec état actif, option actuelle réinjectée au formulaire, filtres historiques, nouvelle association active exigée. |
| Sélection vidée au changement de filtre/page | Remise à zéro synchrone présente. **Réponses tardives non neutralisées : constat 3.** |
| 409 puis nouvelle confirmation ; erreur réseau puis nouvel essai | Rejoués avec le composant réel : deux requêtes explicites, attendu passant de `1` à `2` après 409, dialogue fermé et erreur effacée après succès. |
| Mot de passe hors caches de requêtes et mutations | Création/réinitialisation passent par des fonctions directes ; état local d’affichage, fermeture testée ; réponses `no-store`. |
| Seeder sans référentiels, relançable | Fiche ignorée avec message, aucune liste officielle créée ; trois tests du fichier seeder réussis. |

## Points vérifiés sans remarque

- Les routes T-12 ajoutent une restriction par rôle en plus de `learners.view`, ce qui exclut l’entreprise. CME limitée aux compétences, Direction en lecture seule ; création, modification de fiche, réinitialisation et actions de compte restent réservées à l’administrateur.
- Nouvelle permission attribuée à l’administrateur et à la CME ; matrice mise à jour. Aucun ajout de dépendance dans le changement examiné.
- Migration : CNIE et lien utilisateur uniques, identifiant CME facultatif unique, clés étrangères et pivot sans niveau. Les index des clés étrangères sont fournis par MySQL ; la suppression logique garde la fiche et ses associations.
- Réinitialisation : mot de passe remplacé, sessions fermées, liens annulés et refus sur compte désactivé. Suppression/restauration : accès coupé et état de désactivation conservé. Ces cas figurent dans les tests ciblés réussis.
- L’identité modifiée est auditée par l’observateur de `User`, les champs propres à la fiche par le service ; la réinitialisation supprime l’audit automatique redondant. Les champs sensibles sont filtrés ou masqués ; aucun mot de passe n’est ajouté aux snapshots.
- Catégorie Apprenants et actions de synthèse ajoutées ; consultation de CNIE dans sa catégorie distincte. Les reports validés, dont événements et CV, ne constituent pas des manques de T-12.

## Qualité des tests

Les résultats réussis sont utiles mais ne couvrent pas les cinq constats :

- `test_selection_mal_formee_refusee()` vérifie mélange, liste vide, doublons, identifiant invalide et absence du nombre attendu, **mais pas l’absence simultanée de `ids` et `filters`**. Il passe donc malgré le constat 1.
- Le test d’ordre des verrous vérifie création et modification. Il ne vérifie pas le verrou du compte dans `bulkAddCompetence`, ni une suppression concurrente. Le constat 2 passe à travers.
- Le test CNIE appelle directement le helper pour le LF final et utilise un LF au milieu pour le refus HTTP : il ne démontre pas le refus HTTP demandé par v2.1.
- Le test frontend de 409 passe de un éligible à zéro et désactive le bouton. Il ne démontre pas à lui seul un nouvel essai positif ; ce parcours a été complété par le banc de l’auditeur.
- Les tests de changement de filtre utilisent des réponses rapides ; ils ne retardent pas le comptage après une annulation ou une nouvelle sélection. Ils passent malgré le constat 3.
- Le test de limite vérifie la constante et une liste de 2 001 identifiants ; il ne mesure pas 2 000 opérations ni ne teste à lui seul un filtre donnant trop d’éligibles. Le contrôle serveur de cette limite est présent dans `confirmCount()`.

Ajouter des régressions sur ces trous de couverture apporte plus qu’une relance inchangée de la suite complète.

## Questions au responsable technique

Aucune décision métier nécessaire pour les constats 1 à 4. Pour le constat 5, la décision écrite reste celle du format strict : l’exception `TrimStrings` n’est pas validée par sa seule mention dans `PROGRESS.md`. Tout éventuel report des défauts mineurs reste à décider ; aucun fichier de décisions ou d’erreurs connues n’a été modifié pendant cette revue.

## Validation et limites

### Contrôles exécutés par l’auditeur

- **Backend Docker : PHP 8.4.27, MySQL 8.0.46, base `jobdating_test`, jamais SQLite.** Commande ciblée : `docker compose run --rm … php php artisan test --do-not-cache-result tests/Feature/Learners tests/Feature/Auth/DemoAccountsSeederTest.php` ; options d’environnement de journalisation et de vues temporaires hors dépôt. **36 tests réussis, 304 assertions**, environ 68 secondes.
- **Frontend : Node 24, Vitest 5.1.0.** Deux fichiers ciblés, `learners/learners.test.tsx` et `layout/navigation.test.tsx` : **18 tests réussis**. Lancement par l’API Vitest avec configuration existante chargée nativement, cache de résultats désactivé et cache temporaire hors dépôt supprimé.
- **Bancs backend en mémoire :** application et contrôleur réels, tables préfixées dans `jobdating_test`, définitions et contraintes copiées des tables existantes sans copier leurs données. Données entièrement fictives, deux connexions PDO distinctes pour les entrelacements contrôlés. **31 tables temporaires supprimées après chaque banc ; zéro table restante pour chaque préfixe.** Les scénarios valides finaux ont confirmé les deux défauts majeurs, les acceptations LF/tabulation, la reprise de classe et les réponses sûres aux collisions SQL. Les ajustements nécessaires au banc ne sont pas présentés comme des défauts applicatifs.
- **Bancs frontend en mémoire :** composant réel `LearnersPage`, hooks et composants associés, compilation sans écriture, DOM JSDOM et API simulée avec réponses différées. Scénarios confirmés : annulation puis réouverture tardive, ancien comptage/nouvelle sélection, recherche en erreur masquée, 409 puis succès et erreur réseau puis succès.
- **`git diff --check develop` : réussi.** Aucun fichier de code ni test modifié, aucun commit ni push ; le présent rapport est le seul fichier créé dans le dépôt.

### Mesure H2 effectuée pendant la revue

Sur les tables isolées avec leurs contraintes, **2 000 comptes apprenants fictifs**, chacun avec une session et un lien de réinitialisation : appel réel à `bulkDeactivate()`, **12,657 secondes**, **2 000 comptes modifiés**, **2 001 lignes d’audit ajoutées**, **zéro session et zéro lien restant** pour ces comptes.

La mesure exclut la préparation des données et le trajet HTTP. Les définitions de tables ont été copiées, pas les triggers ; les triggers du journal concernent modification/suppression de ses lignes, opérations absentes de ce scénario. Cette mesure locale comble le manque d’indication H2 signalé par Claude, sans garantir le temps de réponse de production, d’une autre volumétrie de sessions ou d’une charge concurrente.

### Résultats communiqués par Claude, non rejoués intégralement

- Pint réussi ; PHPStan sans erreur.
- Tests Learners : 33 réussis ; `DemoAccountsSeederTest` : 3 réussis. Ces deux fichiers ont été rejoués par l’auditeur, avec les résultats détaillés ci-dessus.
- Suite backend complète Docker : **406 tests réussis, 2 040 assertions**, environ 4 min 20 ; **non relancée** par l’auditeur.
- Frontend : lint réussi, **78 tests réussis**, build réussi ; seuls les 18 tests ciblés ont été rejoués, pas le lint ni le build.
- Navigateur administrateur de démonstration : liste avec CNIE masquée, fiche avec CNIE complète ; résultat communiqué, **non rejoué dans un navigateur par l’auditeur**.

Les contrôles JSDOM ne valident pas le rendu CSS, les breakpoints ou l’accessibilité complète. Les entrelacements MySQL contrôlés démontrent les scénarios indiqués ; ils ne remplacent pas une couverture automatisée exhaustive de concurrence ou une campagne de charge. Aucune donnée de développement utilisée pour les reproductions ; aucune connexion à un service externe de messagerie.

## Verdict

**À corriger avant fusion.** Refuser l’absence de sélection et rétablir le verrouillage/recontrôle des comptes dans l’ajout de compétence en masse. Corriger également les trois écarts mineurs ou faire acter leur traitement lorsqu’un report est justifié. Les tests ciblés réussis ne couvrent pas les défauts reproduits.
