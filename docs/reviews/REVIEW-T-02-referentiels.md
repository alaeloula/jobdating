# Revue indépendante — T-02 (JD-9) : référentiels

- **Date :** 16/11/2026, Africa/Casablanca.
- **Branche :** `feature/T-02-referentiels`.
- **Base :** `develop`, `799d49af57db4b16c2a51f144b95fb82afd34847` ; `HEAD` pointe également sur cette base.
- **État :** développement non commité ; revue du diff et des fichiers non suivis concernés.
- **Livrable :** ce rapport uniquement. Aucun correctif appliqué, aucun fichier de test créé, aucun commit ni push.

## 1. Références et périmètre

Références examinées : `AGENTS.md`, `CLAUDE.md`, plan T-02 **version 3 validée par le responsable technique**, les deux revues du plan, décisions du 16 novembre, `CURRENT_TASK.md`, entrée T-02 de `PROGRESS.md`, backlog, CDC ADM-09/RG-REF-01 à 03, modèle 8.2, ENT-05/PC-23, matrice 3.2 et TRA-01. La maquette `admin-12-referentiels.png`, examinée pendant la revue du plan, sert de référence pour la structure de l'écran.

Les décisions validées priment sur le CDC antérieur : correction de la filière d'une classe utilisée avec confirmation, compétence inactive exclue des suggestions, type d'étiquette modifiable seulement avant utilisation, code de filière définitif, aucune suppression et listes officielles non initialisées automatiquement.

Fichiers examinés : migration des huit tables ; neuf modèles de référentiels, dont le modèle abstrait ; énumérations ; contrats et implémentations provisoires ; `ReferentialController`, `ReferentialService`, `ReferentialsSeeder`, fournisseur de services, routes, modifications du journal ; deux fichiers de tests backend ; écran React, configuration, hooks API, tests frontend, navigation, garde de route et `ApiError.payload`.

Les exclusions demandées sont respectées : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`. Les formulaires consommateurs, les associations de compétences, COMP-01 et les tests de simultanéité des futures relations ne sont pas signalés comme manquants.

## 2. Constats

### 1 — L'unicité des étiquettes n'est pas validée lorsque seul leur type change

- **Sévérité : Mineur.**
- **Fichier et ligne :** `backend/app/Enums/ReferentialType.php:116` ; validation appelée dans `backend/app/Http/Controllers/ReferentialController.php:62`.
- **Qualification : bug confirmé sur MySQL 8.0.46**, avec le contrôleur et le gestionnaire d'exceptions Laravel réels.
- **Référence :** plan v3, unicité `type + libellé` et validation des modifications.

**Scénario reproductible :**

1. Créer une étiquette « Review Anglais » dans les points forts et une autre de même libellé dans les points à améliorer, ce qui est autorisé.
2. Sur la première, envoyer `PATCH /api/v1/referentials/etiquettes/{id}` avec uniquement `{"type":"a_ameliorer"}`.
3. Résultat : **500**, `UniqueConstraintViolationException`, erreur MySQL **1062**. En envoyant aussi `"label":"Review Anglais"`, la même collision donne correctement **422**, avec une erreur sur `label`.

La règle d'unicité est attachée au champ `label`, soumis à `sometimes` en modification. Elle n'est donc pas exécutée si ce champ est absent, même quand le changement de type crée une collision avec le libellé conservé.

**Impact :** un PATCH partiel prévu par l'API échoue en erreur serveur, sans message de correction exploitable. La contrainte SQL protège les données : le type initial reste conservé et aucun journal de modification n'est écrit. Le formulaire React envoie actuellement les deux champs ; le défaut concerne donc surtout les appels partiels directs à l'API.

**Correctif minimal proposé :** valider le couple effectif après modification dès que le type ou le libellé change, en reprenant la valeur actuelle du champ omis. Conserver l'index SQL et ajouter une régression avec deux homonymes puis un PATCH contenant seulement le type.

Le test `ReferentialManagementTest.php:253` ne détecte pas ce défaut : son changement de groupe porte sur des libellés différents.

### 2 — Le réordonnancement accepte un objet JSON à la place d'une liste

- **Sévérité : Mineur.**
- **Fichier et ligne :** `backend/app/Http/Controllers/ReferentialController.php:86` ; utilisation de l'index dans `backend/app/Services/Referentials/ReferentialService.php:140` et `145`.
- **Qualification : bug confirmé sur MySQL 8.0.46.**
- **Référence :** plan v3, ordre d'un groupe complet, validation et atomicité.

**Scénario reproductible :** créer deux opportunités d'identifiants A et B, puis appeler `PUT /api/v1/referentials/opportunites/order` :

- `{"ids":{"a":B,"b":A}}` passe les contrôles de tableau, d'entiers, d'unicité et de groupe complet. Résultat : **500**, `TypeError: Unsupported operand types: string + int` au calcul de la position. L'ordre et le journal restent inchangés grâce à la transaction.
- `{"ids":{"10":B,"20":A}}` est accepté en **204**, mais stocke les positions **11 et 21**, calculées à partir des clés reçues au lieu des rangs successifs.

La règle `array` accepte les tableaux associatifs issus d'objets JSON ; `array_map('intval', ...)` conserve leurs clés. Le service suppose une liste indexée à partir de zéro.

**Impact :** une structure d'entrée invalide provoque une erreur serveur ou laisse le client piloter les positions par les clés de son objet. La sélection des lignes reste limitée au groupe : aucune écriture dans un autre référentiel n'a été constatée.

**Correctif minimal proposé :** ajouter la règle Laravel `list` à `ids`, disponible dans la version installée, pour refuser ces objets en 422. Une réindexation défensive dans le service peut compléter cette validation. Ajouter les deux cas de régression ; ils concernent les opportunités et les étiquettes, qui utilisent le même chemin.

### 3 — Compléter les tests de régression sur les limites de validation et les échecs d'audit

- **Sévérité : Suggestion.**
- **Fichiers et lignes :** `backend/tests/Feature/Referentials/ReferentialManagementTest.php:253`, `306` et `342` ; `frontend/src/referentials/referentials.test.tsx:67` (début du groupe de tests).
- **Qualification : lacunes de couverture confirmées à la lecture ; aucun troisième bug fonctionnel démontré.**

**Scénario :** les tests actuels peuvent rester verts malgré les deux défauts précédents. Les refus de groupes incomplets sont contrôlés avant les écritures : ces tests ne démontrent pas, à eux seuls, le rollback d'une mutation déjà commencée lorsque l'audit échoue. Les six tests frontend ne comprennent pas de scénario de réordonnancement.

**Impact :** ces régressions pourraient passer inaperçues lors d'une modification ultérieure.

**Complément minimal proposé :** ajouter les régressions des constats 1 et 2, puis conserver un cas d'échec d'audit après une modification et après un réordonnancement. Un test frontend du déplacement d'une valeur et du rafraîchissement de l'ordre compléterait le plan. Ces contrôles d'atomicité et de déplacement ont réussi dans les bancs en mémoire de cette revue ; il s'agit de les rendre durables dans la suite, sans refactoring.

## 3. Points vérifiés sans remarque

### Les trois points ajoutés au plan v3

| Point | Vérification | Résultat |
|---|---|---|
| Homonymes du seeder | `ReferentialsSeeder.php:85–104` ; rattachement selon libellé ou type + libellé, puis relance après correction | **Traité.** La clé de l'homonyme est attribuée ; état et position sont conservés ; l'autre type reste indépendant ; aucun ancien libellé ni journal n'est réintroduit. |
| Verrou et contrôle d'utilisation | `ReferentialService.php:61–80` ; sondes dans `ClassUsage` et `TagUsage` | **Traité pour T-02.** Les sondes sont appelées dans la transaction, après une requête contenant `FOR UPDATE`. Le protocole des futurs écrivains est documenté dans les contrats. |
| Confirmation stricte | `ReferentialController.php:67–75`, exception 409 et service | **Traité.** Absent/faux → 409 ; chaîne, nombre ou null → 422 ; vrai booléen → 200. Aucun autre champ ni audit modifié lors des refus ; le nombre envoyé par le client est ignoré. |

### Autres vérifications

- **Droits et routes :** chaque opération de gestion est dans le groupe `referentials.manage`, lui-même sous `auth:sanctum` et `session.valid`. Les options sont en lecture pour tout connecté ; aucune route DELETE n'est ajoutée. Le modèle est choisi depuis l'énumération de l'adresse avant la recherche de l'identifiant. Les tests communiqués couvrent les refus des trois rôles non gestionnaires ; la revue des routes ne révèle pas de contournement.
- **Navigation et garde React :** lien conditionné à la permission, route réservée à l'administrateur et à la CME. L'écran comporte huit onglets, deux groupes d'étiquettes, modification, état, ordre et code de filière en lecture seule. Le déplacement d'une opportunité a été exécuté en mémoire : requête `PUT` avec une vraie liste d'identifiants et tableau rafraîchi dans le bon ordre.
- **Modèle de données :** catalogue `evaluation_tags_ref`, association `evaluation_tags` laissée à T-31, modèle `SchoolClass` explicitement lié à `classes`. La migration prévoit les unicités et une clé étrangère obligatoire avec suppression restreinte vers `filieres`. Cette relation existe aussi dans les métadonnées MySQL de `jobdating_test`.
- **Validation :** format et immutabilité du code, filière obligatoire et existante, limites de longueur et types contrôlés. Un doublon « Review Ecole » / « Review ÉCOLE » donne bien 422 avec la collation `utf8mb4_unicode_ci`. Les exceptions sont les deux constats documentés plus haut.
- **Champs techniques :** les écritures API retiennent seulement les champs validés et autorisés. L'injection de `seed_key` et `position` à la création a été contrôlée : clé restée nulle, position calculée par le serveur, clé absente de la réponse.
- **Options :** actives par défaut, tous états avec `inactives=1`, état présent, ni e-mail de campus ni clé technique. Le maintien des valeurs liées dans les autres ressources reste correctement confié aux tâches consommatrices.
- **Type d'étiquette :** refus 422 lorsque le point d'extension indique une utilisation ; changement autorisé sinon et placement en fin du nouveau groupe. Résolution du contrat à chaque contrôle, sans le figer dans le constructeur.
- **Ordre nominal :** groupe complet et sans doublon, filtrage par type d'étiquette, verrou des lignes, transaction et tri par position puis identifiant. Les clés associatives sont l'exception du constat 2.
- **Journal :** septième catégorie et actions explicitement rattachées ; libellé de sujet via `HasAuditLabel` ; avant/après ; filtre existant alimenté par l'énumération. Un échec d'audit provoqué annule bien une modification ainsi qu'un réordonnancement : données et nombre de journaux inchangés.
- **Données initiales :** cinq opportunités et huit étiquettes fidèles aux libellés validés. Aucune création de compte ; aucune initialisation des six autres listes. Dans le scénario contrôlé, un homonyme manuel non journalisé et une étiquette homonyme de l'autre type donnent neuf étiquettes au total, douze créations auditées, puis aucune nouvelle entrée après relance.
- **Périmètre et conventions :** pas de dépendance ajoutée ; API `/api/v1`, messages français et réutilisation du service d'audit. Les compétences, secteurs et dépendances futures correspondent aux décisions et au backlog.

## 4. Questions au responsable technique

Aucun nouvel arbitrage métier nécessaire pour corriger les deux défauts relevés.

Restent les suivis déjà connus : ENV-01 pour PHP 8.4 exact, COMP-01 pour la saisie en masse, et branchement obligatoire des contrats et du protocole de verrou dans T-12/T-13/T-31. Ils ne sont pas considérés comme des oublis de T-02.

## 5. Validation et limites

### Contrôles exécutés pendant cette revue

Environnement constaté : **PHP 8.5.12**, **MySQL 8.0.46**, collation **`utf8mb4_unicode_ci`**, **Node 24.21.0**. `docker compose up -d mysql` réussit ; conteneur déjà démarré. `git diff --check develop` réussit.

Les bancs PHP sont exécutés en mémoire, sur **`jobdating_test` uniquement**, avec des copies de tables préfixées `review_t02_<pid>_`. Ils utilisent les contrôleurs/services réels, des requêtes JSON et le rendu d'exceptions Laravel. Ils ne traversent pas le cycle HTTP complet ni ses middlewares. Les copies conservent les colonnes et index ; `CREATE TABLE LIKE` ne recopie pas les clés étrangères ni les déclencheurs du journal. Aucune garantie nouvelle sur ces déclencheurs n'est déduite de ces bancs. Toutes les tables de contrôle ont été supprimées, avec vérification de l'absence de tables restantes.

| Contrôle ciblé | Résultat obtenu |
|---|---|
| Collision après changement du seul type d'étiquette | Défaut confirmé : 500/1062 ; même couple avec libellé fourni → 422 ; rollback conservé. |
| Réordonnancement avec clés associatives | Défaut confirmé : clés alphabétiques → 500 ; clés 10/20 → positions 11/21. |
| Confirmation de filière et contrôle sous verrou | Résultats conformes au tableau de la section 3. |
| Étiquette utilisée/non utilisée | 422 puis 200 ; contrôle sous verrou ; position finale 2 après une valeur existante. |
| Doublon casse/accents et injection de champs techniques | 422 pour le doublon ; clé et position injectées non appliquées. |
| Seeder : homonyme, autre type, correction et relance | Conforme ; aucune réintroduction ni nouvelle ligne d'audit. |
| Échec d'audit après modification et réordonnancement | Rollback confirmé dans les deux cas. |
| Déplacement React d'une opportunité | Réussi sous JSDOM, code compilé en mémoire sans fichier généré ; API simulée ; ordre rafraîchi. |

Pas de test de simultanéité : ceux des futures relations sont explicitement reportés à T-12/T-13/T-31. Observer `FOR UPDATE` sous transaction ne remplace pas ces futurs tests concurrents. Aucun nouveau parcours avec navigateur et backend réel n'a été exécuté par cet auditeur.

### Résultats communiqués par Claude, non rejoués

- Pint : réussi ; Larastan : aucune erreur.
- `php artisan test tests/Feature/Referentials` : **29 tests, 200 assertions**, réussis.
- Suite backend complète : **234 tests, 906 assertions**, réussis, code de sortie 0 ; non relancée conformément à la demande.
- Frontend : lint réussi, **26 tests** réussis, build et vérification TypeScript réussis ; commandes non relancées.
- Navigateur avec backend réel et compte CME : huit onglets, ajout d'une filière, formulaire vidé, opportunités initiales et réordonnancement vérifiés par Claude.

Ces résultats sont compatibles avec les deux défauts constatés : leurs scénarios ne sont pas couverts par les tests actuels.

## 6. Verdict

**À fusionner après corrections mineures.**

Corriger la validation du couple type/libellé lors d'un PATCH partiel et imposer une liste pour `ids`, avec leurs tests de régression. Aucun constat bloquant ou majeur retenu. Les trois compléments du plan v3 sont correctement intégrés et les limites des tâches suivantes sont explicites.
