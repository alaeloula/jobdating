# Contre-revue indépendante — T-06 (JD-13) : journal d'audit

- **Date :** 16/11/2026, Africa/Casablanca.
- **Branche :** `feature/T-06-journal-audit`.
- **Base :** `develop`, `71968dfc4b1cce2f551e8c0754ef5ee77778d72d`.
- **État :** travail non commité ; examen du diff avec `develop` et des fichiers non suivis du périmètre.
- **Livrable :** ce rapport uniquement. Aucun code modifié, aucun fichier de test créé dans le dépôt, aucun commit ni push.

## 1. Références et décisions appliquées

AGENTS.md, CLAUDE.md, prompt complet de contre-revue, décisions du 16/11 dans DECISIONS.md, corrections de PROGRESS.md et constats de `REVIEW-T-06-journal-audit.md`.

Fichiers examinés : `AuditLogger.php`, `UserAuditObserver.php`, `AppServiceProvider.php`, contrôleurs d'authentification, migration du journal, modèle `User`, routes d'audit, tests `AuditLogStorageTest.php` et `AuditEventsTest.php`, ainsi que les changements frontend et de documentation du diff complet.

Les décisions suivantes sont appliquées sans remise en cause : CNIE masquée uniquement lorsqu'elle est textuelle ; refus inconnus non journalisés ; auteur « Système » hors requête web ; purge applicative à un an et un jour ; seuil SQL exact d'un an. TRUNCATE, droits SQL, annulation destructive et écritures silencieuses/de masse restent reportés aux tâches indiquées par le responsable technique.

## 2. Rejeu des constats initiaux

| Constat | Statut | Résultat indépendant |
|---|---|---|
| 1 — Variantes de secrets conservées | **Corrigé** | Après écriture et relecture MySQL, `pwd`, `PASSWD`, `mot_de_passe`, `MOT DE PÁSSE`, `api-key`, `credential`, `mdp`, `pass` et un secret profondément imbriqué sont absents. `passage`, `passages` et la note de changement sont conservés. |
| 2 — CNIE non textuelle | **Non corrigé, conformément à la décision validée** | La condition de masquage reste limitée aux chaînes. Une CNIE textuelle fictive est masquée ; la valeur numérique fictive reste inchangée. Ce point n'est pas retenu comme défaut ni comme condition de fusion. |
| 3 — Blocage IP journalisé par cible | **Corrigé** | Un blocage individuel, puis un blocage global dans la même fenêtre et depuis la même IP, donnent exactement **deux lignes**, avec `limite = compte` et `limite = adresse_ip`. Les nouvelles cibles refusées n'ajoutent aucune ligne globale supplémentaire. |
| 4 — Suppression SQL avant un an | **Corrigé** | Le DELETE d'une ligne à `NOW() - INTERVAL 1 YEAR + INTERVAL 12 HOUR` est refusé. La purge conserve celle de plus d'un an et douze heures et supprime celle de plus d'un an et deux jours. Résultat identique avec la session MySQL en `+00:00` et `+01:00`. |

### Constat 1 : filtre et champs légitimes

Références : `backend/app/Services/AuditLogger.php:24`, `:27` et `:101–103` ; note dans `UserAuditObserver`.

Le banc a enregistré les mêmes familles de valeurs fictives que la première revue, avec casse, accent, séparateurs et plusieurs profondeurs. La relecture donne notamment :

```text
nested.deep = []
passage = 3
passages = [1, 2]
note = « Mot de passe modifié »
```

Les valeurs secrètes ne sont plus présentes. La reconnaissance de `pass` en entier évite de supprimer `passage` et `passages`.

`password_changed_at` est filtré : le fragment `password` correspond volontairement au filtre conservateur. Il s'agit d'une métadonnée qui ne serait donc pas conservée sous ce nom. Aucun usage actuel de cette clé n'est requis par le diff ; l'événement de changement de mot de passe utilise désormais `note`. Ce comportement ne fait pas perdre l'information actuellement attendue et n'est pas reclassé en bug.

### Constat 3 : limites individuelle et globale

Références : `backend/app/Providers/AppServiceProvider.php:43–52` et `:86`.

Rejeu avec le vrai middleware `ThrottleRequests` et le limiteur `login`, sous horloge figée :

1. Sept tentatives sur une même cible : `422, 422, 422, 422, 422, 429, 429`.
2. Depuis la même IP, 95 autres cibles atteignent ensuite le seuil global.
3. Cinq nouvelles cibles sont toutes refusées en 429.
4. Le journal contient exactement deux lignes de blocage : `compte`, puis `adresse_ip`.

La réponse 422 des tentatives autorisées était simulée pour isoler le middleware ; les callbacks, le cache de déduplication et les insertions MySQL étaient réels. Ce contrôle vérifie la coexistence des deux limites, en complément du test de régression qui couvre le blocage global seul.

### Constat 4 : seuil et fuseaux

Références : migration `2026_11_16_120000_create_audit_logs_table.php:54` et `AuditLogger.php:78`.

Pour chaque fuseau MySQL essayé, trois lignes ont été insérées avec des dates relatives à l'horloge SQL :

| Âge de la ligne | DELETE/purge observé |
|---|---|
| Un an moins douze heures | DELETE direct refusé ; ligne conservée |
| Un an et douze heures | Conservée par la purge applicative |
| Un an et deux jours | Supprimée par la purge applicative |

La purge a supprimé une ligne à chaque passage. Le jour supplémentaire respecte la décision de conservation d'au moins un an et laisse une marge supérieure au décalage d'une heure testé entre PHP et MySQL. Ce contrôle ne prétend pas couvrir tous les fuseaux mondiaux, les dates bissextiles ou une frontière à la microseconde.

## 3. Nouveaux constats

**Aucun nouveau défaut confirmé nécessitant une correction avant fusion n'a été identifié.**

Les différences expressément acceptées par le responsable technique et les reports vers T-01/T-10 à T-13 ne sont pas transformés en nouveaux constats.

## 4. Qualité des tests de régression

- **Filtre des secrets :** le test fournit les alias initialement conservés et compare le JSON relu. L'ancien code ne satisferait pas cette attente. Le champ `passage` sert de contrôle contre un filtrage excessif de `pass`.
- **Seuil annuel :** l'insertion par expression SQL évite de confondre un décalage PHP avec la règle du déclencheur. L'ancien déclencheur aurait laissé passer la suppression à moins d'un an et douze heures. L'assertion de refus détecte ce défaut.
- **Blocage global :** le test utilise 100 identifiants inconnus puis cinq autres cibles refusées et attend une seule ligne globale. L'ancienne déduplication par cible aurait créé plusieurs lignes. Le banc indépendant confirme aussi un blocage individuel suivi du blocage global dans la même minute.
- **Refus inconnus :** le test exige l'absence de ligne pour un identifiant inconnu puis vérifie le motif, l'identifiant et l'élément lié pour un compte existant. L'ancien code aurait journalisé le premier refus.
- **Auteur système :** le test distingue une invocation directe hors route des événements HTTP. Il vérifie « Système » et IP nulle.
- **Note de changement de mot de passe :** les attentes de l'observateur utilisent la nouvelle clé neutre `note`, cohérente avec le filtre élargi.

L'analyse « échouerait sur l'ancien code » repose sur les assertions et les reproductions précédentes ; aucune mutation de source ni réexécution des suites anciennes n'a été effectuée.

## 5. Points supplémentaires vérifiés

### Refus connus et inconnus

Invocation du contrôleur réel avec deux requêtes de contrôle munies d'une route, même mauvais mot de passe :

- Identifiant inconnu : **422**, message « Identifiants incorrects. », code `invalid_credentials`, **zéro nouvelle ligne de refus**.
- Compte fictif existant : **422**, même message et même code, **une nouvelle ligne** avec l'identifiant et `motif = invalid_credentials` ; sujet lié au bon compte.
- Pour ce refus web sans session : auteur null et IP de contrôle correctement enregistrée. Le frontend conserve donc l'affichage « Visiteur non connecté ».

Les statuts et contenus JSON comparés sont identiques. Aucun test statistique de durée réseau n'a été réalisé ; la différence volontaire d'écriture SQL n'est pas présentée comme une garantie de temps strictement constant.

### Auteur « Système »

Invocation directe sans route : `actor_label = Système`, `ip = null`. Avec une route attachée à la requête, le refus anonyme reste bien attribué à un visiteur sans session avec son IP.

Dans les branchements HTTP actuels, les contrôleurs, le middleware de limitation de route et les observateurs appelés depuis ces contrôleurs s'exécutent après la résolution de route. Aucun faux « Système » n'a été reproduit sur ces chemins. Une commande, un seeder, le planificateur ou un worker de file autonome n'ont normalement pas de route HTTP ; ils suivent donc le chemin hors web. Ces processus n'ont pas été lancés individuellement dans ce banc. Un acteur explicite ou déjà identifié reste prioritaire dans le code ; le contrôle « Système » porte sur l'action sans acteur.

### Intégrité, droits et reports

- Les protections UPDATE, le groupe API `auth:sanctum` / `session.valid` / `audit.view` et le périmètre administrateur sont conservés par lecture du diff.
- La purge ne cible toujours que `audit_logs`.
- La migration avertit désormais explicitement sur TRUNCATE/DROP et sur son `down()` destructif. La séparation des comptes SQL est notée pour T-01.
- Les écritures silencieuses et de masse sont documentées pour les futures tâches. Aucun nouveau défaut n'est retenu sur ces reports validés.
- Aucun changement frontend correctif supplémentaire n'a été demandé ou appliqué par cette contre-revue. Les résultats frontend de la revue précédente restent des résultats historiques, pas une nouvelle exécution.

## 6. Validation et limites

### Exécuté par l'auditeur pendant cette contre-revue

- Lecture du diff et des nouveaux fichiers, des décisions et des tests ; vérification de la branche et de la base.
- `docker compose up -d mysql` : conteneur actif avec le compose courant.
- Banc ciblé en mémoire sous **PHP 8.5.12 et MySQL 8.0.46**, sans SQLite : filtrage avec persistance, refus connus/inconnus, contextes système/web, deux limites dans une même fenêtre et seuils de suppression/purge sous deux fuseaux MySQL.
- Tables de contrôle isolées dans `jobdating_test` sous le préfixe `crx44c5d7_`. Les noms des cibles et déclencheurs de la migration ont été adaptés en mémoire au préfixe ; leurs clauses sont inchangées. Les scénarios de suppression ont uniquement visé ces tables.
- Nettoyage final : **zéro table restante pour ce préfixe**.
- `git diff --check -- backend frontend` : réussi.

Aucun fichier de banc ni test n'a été créé dans le dépôt. Aucun secret réel utilisé. Les suites ci-dessous n'ont pas été relancées, conformément à la demande.

### Communiqué par Claude, non rejoué par l'auditeur

| Contrôle après corrections, 16/11/2026 | Résultat communiqué |
|---|---|
| Pint | Réussi |
| PHPStan | Aucune erreur |
| Tests du journal | 27 tests réussis |
| Suite backend complète | **205 tests réussis, 706 assertions**, environ 19 minutes, code de sortie 0 |

Les contrôles frontend restent ceux exécutés lors de la revue précédente : lint, **20 tests** et compilation réussis. Ils n'ont pas été rejoués ici, les corrections examinées portant sur le backend. PHP 8.4 exact reste non vérifié (ENV-01).

Pas de nouveau parcours navigateur complet, de test de charge ni de validation des comptes SQL de production. Les résultats locaux communiqués par Claude et les reproductions indépendantes ciblées sont distingués explicitement ci-dessus.

## 7. Verdict

**À fusionner.**

Les constats 1, 3 et 4 sont corrigés. Le constat 2 reste volontairement inchangé selon la décision validée. Aucun autre défaut confirmé ne conditionne la fusion dans le périmètre de T-06.
