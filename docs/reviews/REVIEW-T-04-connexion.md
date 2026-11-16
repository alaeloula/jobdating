# Revue indépendante — T-04 (JD-11) : connexion

- **Date :** 14/11/2026, fuseau Africa/Casablanca.
- **Branche :** `feature/T-04-connexion`.
- **Base :** `develop`, commit `4bf7d667bb035efcb74b334545e2652ce7e3d91f`.
- **État examiné :** travail non commité ; `HEAD` est encore le commit de base.
- **Comparaison :** `git diff develop` et lecture des fichiers non suivis du périmètre.
- **Livrable initial :** ce rapport. À la demande complémentaire de l'utilisateur, ajout du test `backend/tests/Feature/Auth/CompanyConcurrentSessionsTest.php` (voir section 7). Aucune implémentation applicative corrigée, aucun commit ni push.

## 1. Références et périmètre

Références relues : AGENTS.md, CLAUDE.md, CURRENT_TASK.md, CDC V2 (AUTH-01, 3.3, 8.2, 9.5 et 10.1), décisions validées du 14/11/2026, entrée T-04 de PROGRESS.md et maquette `00-connexion.png`.

Les décisions du responsable technique sont prises en compte : première connexion apprenant non bloquante, accueil Direction en pilotage lecture seule, photo à partir de 1024 px et dépendances frontend autorisées. Activation, oubli/changement du mot de passe, réinitialisation administrative et journal d'audit restent hors périmètre. Leur absence n'est pas signalée comme un défaut.

Fichiers examinés :

- Backend : `.env.example`, `app/Enums/Role.php`, `app/Models/User.php`, `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `routes/api.php`, `database/factories/UserFactory.php`.
- Nouveaux fichiers backend : les trois contrôleurs de `app/Http/Controllers/Auth/`, `app/Http/Middleware/EnsureSessionIsValid.php`, `app/Http/Requests/Auth/LoginRequest.php`, `app/Http/Resources/CurrentUserResource.php`, migration `2026_11_14_090000_update_users_table_for_authentication.php`, `database/seeders/DemoAccountsSeeder.php` et les quatre fichiers de `tests/Feature/Auth/`.
- Frontend : `package.json`, changements du verrou de dépendances, `vite.config.ts`, `src/App.tsx`, `src/AppRoutes.tsx`, fichiers des dossiers `src/auth/`, `src/lib/`, `src/layout/`, `src/pages/`, `src/test/` et photo `src/assets/campus-youcode.jpg`.
- Lecture complémentaire des configurations session, base et Sanctum, de `phpunit.xml`, de TypeScript strict et des mécanismes Laravel/Sanctum installés.

Les fichiers locaux explicitement exclus du prompt ne sont pas commentés. Le périmètre fonctionnel est respecté ; les défauts ci-dessous concernent sa réalisation.

## 2. Constats

### 1 — Le limiteur par identifiant est contournable par une variante accentuée

- **Sévérité : Majeur.**
- **Fichier et ligne :** `backend/app/Providers/AppServiceProvider.php:38`, clé à la ligne 42 ; recherche du compte dans `backend/app/Http/Controllers/Auth/LoginController.php:35`.
- **Qualification : bug confirmé, reproduit sur MySQL 8.0.46.**

**Scénario reproductible :** créer une entreprise fictive avec le nom d'utilisateur `societe-fictive`. Depuis une même IP, envoyer cinq connexions avec un mot de passe erroné, puis une sixième avec cet identifiant : statuts observés `422, 422, 422, 422, 422, 429`. Utiliser ensuite `société-fictive`, avec le bon mot de passe et la même IP : réponse **200** pour le même compte, au lieu de 429.

La collation `utf8mb4_unicode_ci` résout les deux saisies vers la même ligne. Le limiteur ne fait que `lower(trim(...))` : il crée donc deux compteurs. La différence entre comparaison SQL et comparaison de la clé explique le contournement ; voir aussi les [effets des collations MySQL](https://dev.mysql.com/doc/refman/8.0/en/charset-collation-effect.html).

**Impact :** RG-AUTH-01 et EXI-SEC-03 ne sont pas respectés : plus de cinq tentatives peuvent viser le même compte et la même IP en variant l'écriture. La limite globale de 100/min/IP reste présente ; il ne s'agit pas d'un nombre illimité de tentatives.

**Correctif minimal proposé :** faire partager un compteur aux saisies qui désignent effectivement le même compte, avec une normalisation cohérente entre recherche et limitation, ou une clé fondée sur l'identité résolue du compte. Conserver la limite globale IP et un traitement sûr des identifiants inconnus. Ajouter une régression avec les deux écritures ci-dessus.

### 2 — Une déconnexion échouée est présentée comme une déconnexion réussie

- **Sévérité : Majeur.**
- **Fichier et ligne :** `frontend/src/auth/queries.ts:60` et `frontend/src/layout/AppLayout.tsx:15`.
- **Qualification : bug confirmé, reproduit avec React/jsdom et un rejet réseau de fetch.**

**Scénario reproductible :** ouvrir une session, puis faire échouer `/api/v1/logout` avant que la requête atteigne le serveur, par exemple en coupant le réseau. Cliquer sur « Se déconnecter ». Les deux traitements `onSettled` effacent l'état local et conduisent à la connexion, sans avertissement. Rétablir le réseau et recharger : la session serveur est toujours valable.

Reproduction en mémoire : le rejet de fetch affiche bien le titre « Connexion », aucun bandeau d'erreur n'est présent ; un nouveau montage, avec le serveur toujours connecté, réaffiche « Tableau de bord entreprise ».

**Impact :** l'utilisateur peut quitter un poste partagé en pensant sa session terminée. Le serveur ne peut pas fermer une session sur une requête qu'il n'a pas reçue, mais l'interface doit rendre cet échec explicite. RG-AUTH-04 / EXI-SEC-04 ne doivent pas être présentés comme satisfaits dans ce cas.

**Correctif minimal proposé :** distinguer le succès ou l'absence confirmée de session d'une erreur réseau/serveur. Ne pas afficher un état final de déconnexion silencieux lors de cette dernière ; proposer un message et une nouvelle tentative. Conserver l'effacement des données utilisateur lors d'une déconnexion effective. Ajouter le cas d'échec au test frontend.

### 3 — La migration échoue au retour arrière après avoir déjà détruit des colonnes

- **Sévérité : Majeur.**
- **Fichier et ligne :** `backend/database/migrations/2026_11_14_090000_update_users_table_for_authentication.php:40`, puis ligne 51 ; effet complémentaire de `up()` à la ligne 22.
- **Qualification : bug confirmé, reproduit sur MySQL 8 strict.**

**Scénario reproductible :** appliquer la migration, créer une entreprise avec `email = NULL` (donnée expressément valide dans T-04), puis exécuter son `down()` dans une base de contrôle. Résultat : `QueryException`, erreur MySQL **1138** au passage d'`email` à `NOT NULL`.

Les premières opérations ont déjà supprimé prénom, nom, nom d'utilisateur, téléphone, dates d'état et suppression logique. Après l'échec, les colonnes observées sont :

```text
id, name, email, password, remember_token, created_at, updated_at
```

`email_verified_at` n'a pas été recréée : le schéma est partiellement revenu en arrière, alors que la migration n'a pas terminé. Une nouvelle tentative ne repart pas d'un état intact.

Un autre contrôle, sur une ligne préexistante portant `name = 'Original Fictif'`, confirme que `up()` perd ce nom : après migration, `first_name = ''`, `last_name = ''` et `activated_at = NULL`.

**Impact :** un retour arrière avec des données métier valides peut laisser l'application dans un schéma incohérent et perdre des données. Une montée de version sur une base non vide perd également les anciennes identités. Les tests démarrant sur une base vierge ne détectent pas ces effets.

**Correctif minimal proposé :** vérifier les préconditions de retour arrière avant toute suppression de colonne et définir le traitement des e-mails nuls. Si le retour n'est pas supporté avec ces données, le refuser explicitement avant toute DDL destructive et le documenter. Préserver les identités existantes pendant la transformation ; faire valider la règle de conversion de l'ancien `name`, sans inventer sa séparation prénom/nom. Ajouter une vérification sur données existantes et une entreprise sans e-mail. Une simple transaction Laravel ne suffit pas à annuler les DDL MySQL déjà exécutées.

### 4 — Le retour après connexion perd l'URL demandée

- **Sévérité : Mineur.**
- **Fichier et ligne :** `frontend/src/auth/queries.ts:48`, `frontend/src/auth/guards.tsx:95` et `frontend/src/pages/LoginPage.tsx:33`.
- **Qualification : bug confirmé, reproduit avec les composants réels sous React/jsdom.**

**Scénario reproductible :** ouvrir `/pilotage?filtre=exemple` sans session, puis se connecter avec un compte CME. Résultat observé : `/pilotage`, sans les paramètres demandés.

La mise à jour de `meQueryKey` rend immédiatement l'utilisateur connecté pour `GuestOnly`, qui redirige vers `user.home`. Le retour à `state.from`, situé dans le callback passé à `mutate`, entre en concurrence avec cette navigation et le démontage de la page. Les callbacks de mutation attachés au composant ne constituent pas une garantie après son démontage ; voir la [documentation TanStack sur les mutations](https://tanstack.com/query/latest/docs/framework/react/guides/mutations).

**Impact :** l'exception AUTH-01 « retour à la page demandée après reconnexion » n'est pas entièrement respectée. Le test `frontend/src/auth/auth.test.tsx:139` peut passer malgré le défaut : il demande `/pilotage` avec un rôle dont l'accueil est précisément `/pilotage` et ne vérifie pas les paramètres.

**Correctif minimal proposé :** faire prendre la décision de destination par un seul chemin de navigation, qui conserve le retour interne demandé avant la redirection automatique de `GuestOnly`. Ajouter une assertion sur une destination distincte de l'accueil ou, dès maintenant, sur une URL avec `search`.

### 5 — Un identifiant JSON non textuel déclenche une erreur 500 avant validation

- **Sévérité : Mineur.**
- **Fichier et ligne :** `backend/app/Providers/AppServiceProvider.php:38`.
- **Qualification : bug confirmé, reproduit par le noyau HTTP Laravel.**

**Scénario reproductible :** avec une session/CSRF valide, envoyer :

```http
POST /api/v1/login
Content-Type: application/json
Accept: application/json

{"identifier":["societe-fictive"],"password":"incorrect"}
```

Résultat observé : **500**, `{"message":"Server Error"}` lorsque le débogage est désactivé. Le cast du tableau en chaîne déclenche `Array to string conversion` dans le limiteur, exécuté avant `LoginRequest`.

**Impact :** une entrée invalide n'atteint pas sa validation 422, contrairement à EXI-SEC-06. Elle produit une erreur serveur et du bruit dans les journaux. Aucune trace technique n'a été exposée dans la réponse avec `app.debug=false` : ce constat n'est pas présenté comme une fuite de trace en production.

**Correctif minimal proposé :** normaliser uniquement une valeur textuelle dans le limiteur ; traiter les autres types sans exception afin qu'ils soient rejetés proprement par la validation, tout en conservant leur limitation par IP. Ajouter les cas tableau et objet JSON.

### 6 — Le rendu français des erreurs CSRF n'est pas utilisé hors tests

- **Sévérité : Mineur.**
- **Fichier et ligne :** `backend/bootstrap/app.php:56`.
- **Qualification : bug confirmé, reproduit avec la vérification CSRF active.**

**Scénario reproductible :** hors environnement `testing`, envoyer depuis une origine stateful un POST de connexion sans jeton CSRF. Réponse observée : **419**, `{"message":"CSRF token mismatch."}`. Le champ `code = csrf_token_mismatch` et le message français configurés sont absents.

Laravel transforme `TokenMismatchException` en `HttpException` de statut 419 avant l'appel aux callbacks de rendu. Le callback typé avec l'exception initiale ne correspond donc plus. Ce comportement a été examiné dans le framework installé et dans son [gestionnaire d'exceptions Laravel 12](https://raw.githubusercontent.com/laravel/framework/12.x/src/Illuminate/Foundation/Exceptions/Handler.php).

**Impact :** le contrat JSON et le message français annoncés ne sont pas respectés. Le rejeu frontend fondé sur le statut fonctionne néanmoins ; il ne s'agit pas d'une absence de protection CSRF.

**Correctif minimal proposé :** rendre le JSON prévu pour l'exception HTTP portant le statut 419, sans intercepter indistinctement les autres erreurs. Ajouter un contrôle avec CSRF activé : les tests HTTP Laravel sous `testing` le désactivent normalement.

### 7 — Le traitement peut révéler l'existence d'un compte par sa durée

- **Sévérité : Mineur.**
- **Fichier et ligne :** `backend/app/Http/Controllers/Auth/LoginController.php:39`.
- **Qualification : risque hypothétique d'énumération temporelle ; la différence de chemin est certaine à la lecture, son exploitabilité réseau n'a pas été mesurée.**

**Scénario à vérifier :** comparer plusieurs échecs pour un identifiant inexistant à ceux d'un compte fictif existant avec un mauvais mot de passe, dans les limites de débit. Le court-circuit de `||` saute `Hash::check` dans le premier cas et effectue le calcul coûteux dans le second.

**Impact :** les statuts, messages et codes sont identiques, mais un signal temporel peut renseigner sur l'existence d'un compte. Cela ne donne ni le mot de passe ni une session ; le réseau et le limiteur influencent fortement la possibilité d'observer le signal. Aucun écart temporel chiffré ni attaque réussie n'est affirmé ici.

**Correctif minimal proposé :** effectuer aussi une vérification sur un hash factice de coût équivalent pour un identifiant inconnu, puis retourner la même erreur générique. Ne pas recalculer un hash neuf coûteux à chaque tentative inconnue.

## 3. Autres points vérifiés sans remarque

- Les identifiants inconnus et mauvais mots de passe produisent le même statut 422, le même message et le même code ; les motifs désactivé/non activé/sans rôle sont vérifiés après le mot de passe.
- Les comptes supprimés sont exclus de la recherche par `SoftDeletes`. Le fournisseur d'authentification recherche également les comptes dans ce périmètre.
- La connexion appelle la régénération de session et inscrit `auth.logged_in_at`. La déconnexion serveur invalide la session et régénère le jeton CSRF.
- Rejeu HTTP avec cookies et sessions en base, en réinitialisant les gardes entre les requêtes : connexion 200, `/me` 200, déconnexion 204, puis `/me` 401. La réutilisation du cookie antérieur à la déconnexion donne aussi 401.
- Rejeu avec désactivation après une vraie connexion : la requête suivante reçoit 401 ; après réactivation, la session qui a déjà été invalidée reste refusée.
- Les deux routes protégées actuelles, `/me` et `/logout`, sont dans le groupe `auth:sanctum` + `session.valid`. Les futures routes protégées devront réutiliser ce groupe.
- `CurrentUserResource` utilise une liste explicite de champs ; aucun mot de passe, jeton de session ou CNIE n'y figure.
- `statefulApi()` est activé. Aucun `createToken()` ni trait `HasApiTokens` n'est présent dans les chemins applicatifs examinés. Le guard Sanctum exige ce support pour authentifier un `User` par jeton ; la table `personal_access_tokens` seule ne constitue pas une acceptation de jetons.
- La protection CSRF est effective hors tests : POST sans jeton rejeté en 419, récupération du cookie puis POST avec l'en-tête XSRF accepté en 200.
- Les contraintes d'unicité de l'e-mail et du nom d'utilisateur restent présentes. L'e-mail nullable est cohérent avec les entreprises ; le retrait de `email_verified_at` n'entre pas en conflit avec une implémentation de `MustVerifyEmail`, qui n'est pas utilisée.
- La factory attribue les rôles en `afterCreating`, conformément à T-03. Le seeder de démonstration est réservé à `local`, absent de `DatabaseSeeder` et testé pour sa relance et son refus hors local. Les identités utilisées sont fictives.
- Les gardes React dirigent l'interface sans remplacer les vérifications serveur. Accueil Direction en lecture seule et redirection d'un utilisateur déjà connecté hors de `/login` : couverts par les tests.
- Le cache TanStack Query est effacé lors du traitement de déconnexion. Le défaut de confirmation serveur en cas d'erreur est décrit au constat 2.
- Le client limite le rejeu après 419 : deux réponses 419 successives ont donné exactement deux appels de la requête métier, puis une erreur 419. L'événement API 403 a également produit le message d'accès refusé et l'événement 401 le message de session expirée dans le contrôle React en mémoire.
- TypeScript strict et compilation passent ; aucun `any` injustifié relevé dans les nouveaux fichiers examinés.
- Maquette ouverte et comparée aux captures Chrome : structure générale, labels et aide entreprise cohérents, compte tenu des éléments explicitement différés. À 375 px, largeur du document égale à 375 px, sans débordement horizontal ; photo masquée à 1023 px, visible à 1024 px.

## 4. Questions au responsable technique et limites de périmètre

Ces points ne sont pas classés comme de nouveaux bugs :

1. **Base existante :** les données antérieures à T-04 doivent-elles être conservées ou la base de développement est-elle officiellement jetable ? Cela détermine la reprise de `name` et de l'activation. Le rollback destructif décrit au constat 3 reste reproductible avec une entreprise valide créée après migration.
2. **Session sans horodatage :** le middleware démarre le délai à la première requête lorsque la clé manque. Le login normal écrit toujours cette clé et aucun paramètre de requête ne permet de l'effacer. Aucun contournement exploitable par un visiteur n'est démontré. Confirmer si cette compatibilité réservée notamment aux tests doit rester autorisée en production ou si une session incomplète doit être refusée.
3. **Mise en ligne derrière un proxy :** confirmer les adresses des proxies de confiance et les en-têtes transmis à Laravel, afin que `$request->ip()` représente correctement le client. Ne pas déduire la configuration de production du fonctionnement local ; ce point relève de T-01/ARCH-01. Vérifier aussi HTTPS, les cookies Secure et les domaines stateful de production à ce stade.
4. **Révocation administrative future :** lors des tâches qui désactivent/réactivent les comptes, prévoir la suppression de toutes leurs sessions pour qu'une session inactive pendant une désactivation ne puisse pas revenir après réactivation. T-04 vérifie la désactivation lors des requêtes ; les actions administratives ne sont pas implémentées ici.
5. **Navigation `state.from` :** les gardes actuels construisent ce retour à partir de l'URL interne visitée. Aucun vecteur externe permettant à un attaquant de fournir cet état n'a été établi. Ce rapport ne qualifie donc pas ce mécanisme de redirection ouverte exploitable.

## 5. Validation et limites

### Contrôles du projet

Environnement réel : **PHP 8.5.12**, **MySQL 8.0.46** sur le service Docker local, **Node 24.21.0**. PHP 8.4 exact n'a pas été vérifié ; ENV-01 reste ouvert. Aucun test backend sous SQLite.

| Commande | Résultat de cette revue |
|---|---|
| `php vendor/bin/pint --test` | Réussi, aucune correction appliquée |
| `php vendor/bin/phpstan analyse --no-progress` | Réussi : `No errors` |
| `php artisan test --no-ansi` | Réussi : **169 tests, 380 assertions**, durée **918,33 s** (15 min 18 s), code de sortie 0 |
| `npm.cmd run lint` | Réussi |
| `npm.cmd run test` | Réussi : 1 fichier, **10 tests** |
| `npm.cmd run build` | Réussi : vérification TypeScript et compilation Vite |
| `git diff --check -- backend frontend` | Réussi ; avertissements de conversion LF/CRLF uniquement |

Les résultats annoncés par Claude pour les six contrôles principaux sont confirmés par cette exécution indépendante. Ils ne couvrent pas les défauts reproduits par les contrôles ciblés ci-dessous.

### Vérifications ciblées supplémentaires

- Scénarios Laravel exécutés par le noyau HTTP avec un jeu de cookies, sessions en base et MySQL strict. Tables isolées dans `jobdating_test` par le préfixe `cr04a9fe_`, supprimées en fin de contrôle : **zéro table restante pour ce préfixe**. Les tables applicatives n'ont pas été utilisées pour ces scénarios supplémentaires.
- Les contrôles couvrent le tableau JSON invalide, la variante accentuée après blocage, une vraie succession de requêtes login/me/logout, la réutilisation d'un ancien cookie, la désactivation, le CSRF actif et la migration sur données existantes.
- Les scénarios frontend supplémentaires ont chargé les composants réels via Vite dans jsdom, sans ajouter de fichier de test : rejet réseau à la déconnexion, remontage avec session serveur toujours valable, retour avec paramètres, événements 401/403 et rejeu 419 borné. Les réponses serveur y sont simulées : ces contrôles ne sont pas présentés comme des parcours navigateur complets vers MySQL.
- Chrome headless a rendu la page réelle à 375, 1023 et 1024 px. La lecture initiale `/me` y était simulée en 401 pour isoler la présentation de la page anonyme. Captures inspectées pour 375 et 1024 px, stockées uniquement dans le dossier temporaire système ; aucune capture ajoutée au dépôt.
- Aucun parcours navigateur authentifié complet avec le backend réel n'a été relancé ; la vérification avec cookies via le noyau HTTP et les contrôles React sont distingués ci-dessus.
- Aucune mesure statistique de temps de réponse, campagne de concurrence du limiteur ou validation de configuration proxy de production n'a été réalisée.

### Qualité et limites des tests existants

- `forgetGuards()` dans le test de déconnexion ne démontre pas à lui seul la persistance d'une session. Le contrôle supplémentaire avec cookies et stockage database confirme ici le comportement serveur réel, y compris le refus de l'ancien cookie.
- Les tests de durée/désactivation avec `actingAs` vérifient le middleware sur des états construits, pas à eux seuls le cycle de connexion par cookie. Ils ne couvrent pas le contrat d'une session sans horodatage en production.
- Le test du seeder change bien l'environnement pour tester la branche locale puis restaure `testing` avant le POST. Ce changement ne prouve pas la protection CSRF hors tests ; cette dernière a été contrôlée séparément.
- Le test de retour après connexion peut passer malgré la perte de la destination : constat 4.
- Les cas importants manquants sont ceux reproduits dans les constats : équivalence des identifiants selon la collation, entrée non textuelle avant validation, échec réseau de déconnexion, retour avec paramètres, migration sur données existantes/entreprise sans e-mail et JSON 419 avec CSRF effectivement actif.

Les scripts de contrôle n'ont corrigé aucun code. Les seuls fichiers supplémentaires générés hors de ce rapport sont les caches/journaux/builds usuels des outils et les artefacts temporaires de navigateur.

## 6. Verdict

**À corriger avant fusion.**

Les constats majeurs 1 à 3 doivent être corrigés puis leurs régressions vérifiées. Les contrôles verts actuels ne couvrent pas ces scénarios. Les décisions validées et fonctionnalités explicitement différées ne sont pas remises en cause.

## 7. Complément demandé — plusieurs recruteurs sur le même compte Entreprise

**Date :** 14/11/2026. Ajout explicitement demandé par l'utilisateur après la revue initiale.

**Référence :** CDC, annexe C, PC-05 : un compte Entreprise est partagé par ses recruteurs ; plusieurs connexions simultanées sont autorisées. Il s'agit de plusieurs sessions du même compte et du même rôle, sans création de comptes recruteurs distincts.

**Fichier ajouté :** `backend/tests/Feature/Auth/CompanyConcurrentSessionsTest.php`.

Deux tests de régression vérifient :

1. Deux navigateurs se connectent par `POST /api/v1/login` avec les mêmes identifiants Entreprise, depuis la même IP. Deux sessions distinctes sont persistées dans MySQL et les deux jeux de cookies permettent d'obtenir le même utilisateur par `/me`. La déconnexion du premier invalide sa session tout en laissant le second connecté ; le second peut ensuite se déconnecter indépendamment.
2. Après deux connexions actives, la désactivation du compte refuse les deux navigateurs à leur requête suivante et supprime les anciennes sessions. Après réactivation du compte, ces navigateurs restent déconnectés : une simple réponse 401 pendant la désactivation ne suffirait pas à prouver cette invalidation.

Le test utilise le pilote de session `database` dans la base MySQL de test, deux jeux de cookies chiffrés conservés séparément, et recrée les gardes et les instances de session entre les requêtes. Il n'utilise pas `actingAs`. Les identifiants des sessions persistées sont vérifiés ; le test ne se contente pas de comparer des cookies dont le chiffrement pourrait changer.

**Résultats locaux :** PHP 8.5.12, MySQL 8.0.46.

| Contrôle | Résultat |
|---|---|
| `php artisan test --filter=CompanyConcurrentSessionsTest --no-ansi` | **2 tests réussis, 36 assertions**, durée **28,19 s** |
| `php vendor/bin/pint --test tests/Feature/Auth/CompanyConcurrentSessionsTest.php` | Réussi |
| `php vendor/bin/phpstan analyse --no-progress tests/Feature/Auth/CompanyConcurrentSessionsTest.php` | Réussi, aucune erreur ; analyse ciblée explicite car la configuration habituelle ne couvre que `app/` |

Les connexions sont établies successivement puis maintenues actives ensemble, ce qui vérifie le partage du compte entre recruteurs. Ce contrôle n'est pas un test de charge ni de requêtes d'écriture métier exécutées en parallèle.

La suite complète n'a pas été relancée après cet ajout : son résultat antérieur reste **169 tests / 380 assertions** ; le résultat complémentaire ci-dessus porte uniquement sur les deux nouveaux tests. Aucune route, aucun contrôleur et aucune règle métier n'ont été modifiés. Le verdict de la revue initiale reste **À corriger avant fusion** pour les constats déjà détaillés.
