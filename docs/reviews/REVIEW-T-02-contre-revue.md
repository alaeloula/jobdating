# Contre-revue indépendante — T-02 (JD-9) : référentiels

- **Date :** 16/11/2026, Africa/Casablanca.
- **Branche :** `feature/T-02-referentiels`.
- **Base :** `develop`, `799d49af57db4b16c2a51f144b95fb82afd34847` ; `HEAD` identique à cette base.
- **État :** travail toujours non commité ; diff avec `develop` et fichiers non suivis recensés.
- **Livrable :** ce rapport uniquement. Aucun fichier de code modifié, aucun test créé dans le dépôt, aucun commit ni push.

## 1. Références et périmètre

Consignes appliquées : `AGENTS.md`, `CLAUDE.md` et demande de contre-revue. Références : `REVIEW-T-02-referentiels.md`, paragraphe « Revue du code par Codex… » de `PROGRESS.md:191`, contrat du plan v3 et décisions validées déjà utilisées lors de la revue initiale.

Examen limité aux corrections de `ReferentialType::rules()`, `ReferentialController::order()` et `messages()`, `ReferentialService::reorder()`, ainsi qu'aux nouveaux tests backend et frontend. Les fichiers corrigés étant encore non suivis, leur contenu a été lu directement : le seul diff Git des fichiers suivis ne suffit pas à les examiner.

Le reste de T-02 n'est pas réaudité. Les exclusions demandées sont respectées : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.

## 2. Rejeu des constats initiaux

| Constat | Statut | Résultat indépendant |
|---|---|---|
| 1 — Collision lors d'un changement du seul type d'étiquette | **Corrigé** | Deux étiquettes de même libellé, une par type ; PATCH de la première avec uniquement `type: a_ameliorer` : **422** sur `type`, au lieu de 500/1062. Type conservé, aucun nouveau journal. |
| 2 — Objet JSON accepté comme liste d'ordre | **Corrigé** | Objets à clés `a/b` puis `10/20` : **422** sur `ids`, au lieu de 500 puis 204 avec positions 11/21. Positions et journal inchangés. Une vraie liste reste acceptée et produit les positions 1/2. |

### Constat 1 : couple effectif et messages

Références : `backend/app/Enums/ReferentialType.php:117–128`, `backend/app/Http/Controllers/ReferentialController.php:148–149`.

La règle ajoutée sur `type` utilise le libellé reçu ou, s'il manque, celui du modèle actuel. La règle sur `label` conserve la logique symétrique. Toutes deux ignorent l'identifiant du modèle modifié.

Contrôles exécutés sur MySQL :

| Cas | Résultat |
|---|---|
| Création avec les deux champs ; homonyme dans l'autre type | 201 pour chaque valeur. |
| Type seul vers un couple déjà présent | 422 sur `type`, aucune mutation ni audit. |
| Libellé seul vers un homonyme du même type, casse différente | 422 sur `label`. |
| Type et libellé vers un couple déjà présent | 422 sur les champs concernés. |
| Modification libre du type seul, du libellé seul ou des deux | 200 ; changement de groupe placé en fin d'ordre. |
| Renvoi du couple inchangé | 200, aucun nouveau journal. |
| « Review Café » face à « REVIEW CAFE », après changement de type | 422 : casse et accents comparés selon la collation MySQL. |
| Type inconnu, nombre ou tableau | 422 avec « Type d’étiquette inconnu. », sans message d'unicité à sa place. |

Le message spécifique de collision est bien « Une étiquette de ce type porte déjà ce libellé. ». La règle `type.unique`, déclarée avant `type.*`, ne remplace pas le message d'énumération lorsque le type est invalide.

**Étiquette utilisée :** avec `TagUsage` remplacé en mémoire par une sonde répondant vrai, un changement de type portant sur un couple libre atteint le service et reste refusé en 422 avec le message d'utilisation. Aucun champ ni journal n'est modifié. Si la demande présente aussi une collision, la validation d'unicité refuse avant le service : le message de collision est prioritaire, mais le changement demeure impossible. La correction du libellé seul et le renvoi du type inchangé restent autorisés, conformément aux décisions.

### Constat 2 : forme de la liste et positions

Références : `backend/app/Http/Controllers/ReferentialController.php:87–96`, `backend/app/Services/Referentials/ReferentialService.php:140–149`.

La règle `list` refuse les deux objets des reproductions initiales. Le service utilise désormais un compteur indépendant des clés.

Contrôles complémentaires exécutés :

- Liste vide, élément null, chaînes non numériques et liste de listes : **422**.
- Groupe incomplet, doublon et identifiant d'un autre groupe d'étiquettes : **422**, sans écriture d'audit.
- Liste d'entiers complète et inversée : **204**, ordre inversé et positions **1/2**.
- Chaînes numériques représentant les identifiants : **204**, conversion en entiers et positions **1/2**. C'est le comportement déjà prévu par la règle `integer` et `array_map('intval', ...)`, sans nouvelle exigence de type JSON strict.
- Appel direct du service avec des clés associatives : positions **1/2**. La défense du service fonctionne aussi indépendamment du rejet HTTP.

### Suivi de la suggestion 3

**Traitée sur l'essentiel.** Les régressions des deux défauts, les échecs d'audit et la requête de déplacement frontend ont été ajoutés. La vérification du rafraîchissement visuel après déplacement reste une amélioration facultative, détaillée ci-dessous.

## 3. Nouveaux constats

Aucun nouveau bug fonctionnel retenu dans le périmètre des corrections.

### Suggestion — Vérifier aussi le nouvel ordre affiché dans le test frontend

- **Fichier et ligne :** `frontend/src/referentials/referentials.test.tsx:147–168`.
- **Qualification : limite de couverture confirmée ; aucune régression du code démontrée.**
- **Scénario :** le GET simulé renvoie toujours le même ordre, même après le PUT. Le test vérifie les boutons de bord et le corps `{ ids: [8, 7, 9] }`, mais ne vérifie ni le rechargement ni le nouvel ordre du tableau. Il pourrait donc rester vert si le rafraîchissement après succès disparaissait.
- **Impact :** une régression d'affichage après un déplacement pourrait ne pas être détectée par ce test.
- **Complément minimal proposé :** faire évoluer la réponse GET simulée après le PUT et attendre que le tableau affiche le nouvel ordre. Le hook actuel conserve bien son invalidation après succès ; cette suggestion ne conditionne pas la fusion.

## 4. Qualité des nouveaux tests

| Test | Évaluation par rapport à l'ancien code |
|---|---|
| `test_changement_de_type_seul_vers_un_homonyme_refuse_proprement` (`ReferentialManagementTest.php:275`) | **Aurait échoué** : la première reproduction donnait 500, alors que le test exige 422 et le message exact. Il couvre aussi le libellé seul et l'absence de conflit avec la ligne elle-même. |
| `test_l_ordre_refuse_un_objet_json` (`:368`) | **Aurait échoué** : l'ancien code donnait 500 pour les clés alphabétiques et 204 pour les clés numériques discontinues. Les assertions sur positions et nombre de journaux sont pertinentes. |
| `test_un_echec_du_journal_annule_la_modification_et_l_ordre` (`:387`) | **Pouvait déjà passer avant les corrections**, car les transactions existaient. Il protège une garantie correcte, jusque-là non couverte par un test durable. Les données sont créées avant la résolution du contrôleur, puis le journal défaillant est lié avant la première requête : le remplacement n'arrive pas trop tard. |
| « déplace une opportunité et envoie le nouvel ordre complet » (`referentials.test.tsx:145`) | **Pouvait déjà passer avant les corrections backend** : l'API est simulée et le frontend envoyait déjà une liste correcte. Il couvre utilement le déplacement demandé et l'envoi du groupe complet, avec la limite d'affichage signalée plus haut. |

Le test d'audit attend volontairement 500 : ce résultat seul pourrait provenir d'une autre erreur avant mutation. Le banc indépendant de cette contre-revue a donc compté les appels au journal et observé les données **au moment où il lève l'exception** :

- Deux appels observés, pour `referential.updated` puis `referential.reordered`, dans une transaction active.
- Au premier appel, le libellé était déjà modifié dans la transaction ; au second, les positions étaient déjà inversées.
- Après les exceptions, libellés et positions d'origine retrouvés, nombre de journaux inchangé.

Le rollback est donc réellement exercé sur le code actuel. Compter les appels du double de test renforcerait aussi cette garantie dans le test permanent.

## 5. Points vérifiés sans remarque

- Les deux corrections traitent leurs reproductions initiales et restent limitées à la validation et au calcul des positions.
- Le contrôle d'utilisation des étiquettes reste appliqué par le service après validation ; aucune nouvelle possibilité de changer le type d'une étiquette utilisée n'a été relevée.
- Le contrôle du groupe complet, des doublons et des identifiants étrangers reste opérationnel.
- Les refus de validation ne modifient ni les données ni le journal.
- Les mutations et leurs journaux restent dans la même transaction, y compris le nouvel algorithme de réordonnancement.
- Aucun nouvel arbitrage métier nécessaire. Les sujets déjà reportés aux tâches suivantes ne sont pas rouverts par cette contre-revue.

## 6. Validation et limites

### Exécuté par cet auditeur

Environnement constaté : **PHP 8.5.12**, **MySQL 8.0.46**, collation **`utf8mb4_unicode_ci`**, **Node 24.21.0**. `docker compose up -d mysql` réussit ; conteneur déjà démarré. `git diff --check develop` réussit.

Les scénarios des sections 2 et 4 ont été exécutés avec des bancs PHP en mémoire, sur **`jobdating_test` exclusivement**, dans des copies de tables préfixées `review_t02_counter_<pid>_`, supprimées ensuite. Les requêtes JSON, contrôleurs, validation, services, accès MySQL et rendu des exceptions Laravel sont réels ; `TagUsage` et le journal ont été remplacés en mémoire pour les scénarios indiqués.

Ces bancs ne traversent pas les middlewares du cycle HTTP complet. Les tables copiées conservent colonnes et index ; elles ne recopient pas les clés étrangères ni les déclencheurs du journal. Aucune nouvelle conclusion sur ces éléments, les droits déjà revus ou la simultanéité n'est tirée ici. Aucun nouveau contrôle frontend n'a été exécuté : son nouveau test a été examiné, sans rejouer la suite.

Les contrôles de suppression des tables préfixées sont revenus vides. Aucun fichier de banc de contrôle n'a été écrit dans le dépôt.

### Communiqué par Claude, non rejoué par cet auditeur

- Pint : réussi ; Larastan : aucune erreur.
- Référentiels backend : **32 tests réussis, 229 assertions**.
- Suite backend complète : **237 tests réussis, 935 assertions**, environ 23 minutes, code de sortie 0.
- Frontend : lint réussi, **27 tests réussis**, build et vérification TypeScript réussis.

La suite complète et les commandes déjà exécutées par Claude n'ont pas été relancées. La différence PHP 8.5/8.4 reste le point ENV-01 déjà ouvert.

## 7. Verdict

**À fusionner.**

Les deux constats mineurs sont corrigés et leurs reproductions passent désormais avec les refus attendus. Aucun nouveau défaut nécessitant une correction avant fusion n'a été relevé. Les renforcements de tests proposés restent facultatifs.
