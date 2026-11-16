# Contre-revue indépendante — T-04 (JD-11) : connexion

- **Date :** 16/11/2026, fuseau Africa/Casablanca.
- **Branche :** `feature/T-04-connexion`.
- **Base :** `develop`, `4bf7d667bb035efcb74b334545e2652ce7e3d91f`.
- **État :** travail toujours non commité ; comparaison de `git diff develop` et des fichiers non suivis du périmètre.
- **Livrable :** ce rapport uniquement. Aucun fichier de code modifié, aucun commit ni push.

## 1. Références, décisions et fichiers examinés

Références : AGENTS.md, CLAUDE.md, tâche T-04, revue initiale `REVIEW-T-04-connexion.md`, corrections du 16/11 dans PROGRESS.md, décisions du 14/11 et décisions explicites du prompt de contre-revue. Les règles de référence restent AUTH-01, RG-AUTH-01/02/04, RG-ACC-05/06, Sanctum par cookie et PC-05 pour le compte Entreprise partagé.

Les décisions du 16/11 sont appliquées : base de développement jetable, absence de reprise de l'ancien `name` acceptée, refus d'une session sans heure de connexion. Les questions de proxy et de révocation administrative globale restent reportées à T-01 et T-10/T-12. Elles ne sont pas requalifiées en défauts de T-04.

Fichiers examinés :

- Backend : `app/Models/User.php`, `app/Providers/AppServiceProvider.php`, `app/Enums/Role.php`, `app/Http/Controllers/Auth/*.php`, `app/Http/Middleware/EnsureSessionIsValid.php`, `app/Http/Requests/Auth/LoginRequest.php`, `app/Http/Resources/CurrentUserResource.php`, `bootstrap/app.php`, `routes/api.php`, `database/factories/UserFactory.php`, migration d'authentification et `DemoAccountsSeeder.php`.
- Tests : `tests/TestCase.php` et les six fichiers de `tests/Feature/Auth/`, notamment `UsersMigrationTest.php` et `CompanyConcurrentSessionsTest.php`.
- Frontend : changements de dépendances/configuration et fichiers de `src/auth/`, `src/lib/`, `src/layout/`, `src/pages/`, `App.tsx`, `AppRoutes.tsx` et configuration des tests.
- Configurations MySQL, sessions, cache, Sanctum et tests, ainsi que sources TanStack installées pour distinguer les caches de requêtes et de mutations.

Les exclusions du prompt sont respectées. Le fichier `.env` n'a pas été affiché ou examiné ; le framework charge naturellement son environnement pour les exécutions locales.

## 2. Rejeu des sept constats initiaux

| # | Constat initial | Statut | Preuve observée |
|---|---|---|---|
| 1 | Variante accentuée contournant le limiteur | **Corrigé** | Après cinq échecs sur `societe-fictive`, la sixième tentative reçoit 429 et la variante `société-fictive`, même avec le bon mot de passe et la même IP, reçoit également **429**. |
| 2 | Déconnexion échouée présentée comme réussie | **Corrigé** | Un rejet réseau maintient `/pilotage?filtre=exemple`, l'utilisateur connecté et le message d'échec. La déconnexion 204 et le cas 401 sont couverts par les tests. La purge mémoire présente néanmoins un nouveau défaut distinct, décrit en section 3. |
| 3 | Annulation de migration partiellement destructive | **Corrigé dans le périmètre validé** | Avec une entreprise sans e-mail, `down()` refuse avant modification : colonnes inchangées et compte intact. Après renseignement de son e-mail dans la base isolée, `down()` puis `up()` réussissent. La non-reprise de l'ancien `name` est acceptée par décision du responsable technique. |
| 4 | Perte des paramètres de retour après connexion | **Corrigé** | Le rejeu React arrive exactement sur **`/pilotage?filtre=exemple`**. Un seul chemin de navigation gère le retour. Les tests vérifient aussi l'accueil propre au compte suivant après déconnexion volontaire. |
| 5 | Identifiant non textuel provoquant une erreur 500 | **Corrigé** | Tableau, objet JSON, entier, booléen et valeur nulle reçoivent tous **422**. Après ces valeurs invalides, un identifiant légitime depuis la même IP peut encore se connecter : **200**. |
| 6 | 419 non traduit lorsque le CSRF est actif | **Corrigé** | Hors `testing`, le POST sans jeton reçoit 419 avec le message français et `code = csrf_token_mismatch`. Après récupération du cookie et envoi de l'en-tête XSRF, le POST reçoit **200**. |
| 7 | Saut du calcul de hash pour un identifiant inconnu | **Corrigé pour la différence de traitement relevée** | Le test impose un appel à `Hash::check` avec le hash factice. Avec le cache `database`, le hash est créé au coût configuré, réutilisé, recréé après suppression puis calculé sous une nouvelle clé lorsque le coût change. Aucune égalité absolue des temps de réponse réseau n'est affirmée. |

### Détail des vérifications backend

Les contrôles ciblés ont utilisé MySQL 8.0.46, des sessions en base et le cache **database**, dans des tables isolées de `jobdating_test`.

- Limiteur : séquence canonique `422, 422, 422, 422, 422, 429`, puis variante accentuée **429**.
- Entrées invalides : cinq réponses **422** ; connexion valide suivante depuis la même IP **200**. Le compteur `login:invalid` ne consomme pas le compteur propre au compte légitime ; la limite globale IP reste intentionnellement commune.
- Identifiant existant avec mauvais mot de passe et identifiant inconnu : mêmes statut **422**, message « Identifiants incorrects. », code `invalid_credentials`, limite annoncée **5** et nombre restant **4**, pour des compteurs fraîchement ouverts. Les cookies et autres valeurs aléatoires de session ne sont pas comparés octet par octet.
- Hash factice : coût observé **4**, réutilisation du même hash, régénération après suppression de sa clé, puis coût **5** sous la clé correspondante après changement de configuration et renouvellement du hasher dans le processus de contrôle. Aucun hash ni mot de passe n'a été affiché.
- Une vraie connexion suivie de `/me`, avec transmission du cookie et renouvellement des gardes entre requêtes, donne **200 / 200** malgré le refus des sessions sans horodatage.
- CSRF actif : erreur française **419**, puis connexion **200** avec jeton valide ; route inconnue **404** avec JSON prévu ; mauvaise méthode sur `/login` **405**, non transformée en 419.
- Migration : refus avec e-mail nul, structure et compte inchangés ; chemin autorisé `down()` puis `up()` exécuté avec succès après suppression de cette précondition dans les données fictives.

## 3. Nouveau constat

### 1 — Les mutations conservent l'identité et le mot de passe après déconnexion

- **Sévérité : Mineur.**
- **Fichier et ligne :** `frontend/src/auth/queries.ts:82`, en relation avec la mutation de connexion aux lignes 45 à 59.
- **Qualification : bug confirmé, reproduit avec les composants réels sous React/jsdom.**

**Scénario reproductible :**

1. Effectuer une connexion depuis l'écran réel avec un compte fictif et conserver une référence au `QueryClient` dans le banc de contrôle.
2. Ajouter une donnée fictive sous une clé de requête hors `auth`, puis se déconnecter avec une réponse serveur 204.
3. Une fois la page « Connexion » affichée, inspecter le cache des requêtes et `queryClient.getMutationCache().getAll()`.

Résultats relevés après la séquence connexion, échec réseau de déconnexion, puis déconnexion réussie :

```text
auth/me = null
requête privée hors auth = absente
mutations conservées = 3
identité de l'ancien utilisateur présente dans mutation.state.data = oui
mot de passe saisi présent dans mutation.state.variables = oui
```

Les journaux du contrôle n'affichent que ces booléens, aucun identifiant secret ni mot de passe.

`removeQueries()` ne traite que le cache des requêtes ; il ne purge pas le cache des mutations. La distinction est confirmée dans les sources installées et les documentations [QueryClient](https://tanstack.com/query/latest/docs/reference/QueryClient) et [MutationCache](https://tanstack.com/query/latest/docs/reference/MutationCache). L'ancien `queryClient.clear()` traitait les deux caches ; son remplacement a donc laissé cette rétention.

**Impact :** l'engagement de nettoyage après déconnexion, rappelé au point B du prompt et dans le commentaire du code, n'est pas entièrement tenu. L'identité et les paramètres de connexion restent accessibles dans l'état JavaScript de la page jusqu'à leur nettoyage automatique. Ce constat ne démontre ni transfert réseau, ni accès distant, ni récupération de session ; il n'est pas présenté comme un contournement des autorisations serveur. La portée observée est une rétention évitable de données sensibles en mémoire, notamment sur un poste partagé.

**Correctif minimal proposé :** purger ou réinitialiser aussi les mutations d'authentification conservant les données et variables du compte précédent, lors d'une déconnexion confirmée, sans réintroduire la suppression des requêtes `auth` observées qui avait perturbé la navigation. Ajouter une assertion sur le cache de mutations après succès et après le cas 401. Aucun correctif appliqué.

## 4. Qualité des tests de régression

| Test ou groupe | Analyse de la protection apportée |
|---|---|
| Variantes dans `LoginRateLimitTest` | Les écritures avec casse, espaces et accents désignent le même compte. Les assertions 429 sur les variantes accentuées détectent le défaut initial. |
| Entrées non textuelles | Le test attend 422 et des erreurs de validation. L'ancien cast de tableau produisait 500 ; il ne satisferait pas ces assertions. |
| Hash factice dans `LoginTest` | `Hash::partialMock()` impose exactement un `check` avec les paramètres attendus. Le court-circuit initial laisserait cette attente non satisfaite. Le test préremplit le cache : il ne prouve pas, à lui seul, la génération ou le renouvellement du hash ; ces comportements ont été contrôlés séparément sur le cache database. |
| Session sans heure | `be()` permet de ne pas injecter l'heure. L'ancien middleware acceptait la requête ; l'assertion 401 protège maintenant cette décision. |
| Cycle login/me/logout | L'absence d'`actingAs` est utile, mais le garde et la session peuvent rester en mémoire dans un test HTTP Laravel. Ce test seul n'est pas une preuve complète de deux navigateurs. Les tests `CompanyConcurrentSessionsTest`, avec cookies séparés et instances renouvelées, apportent cette vérification. |
| CSRF 419 | Le passage explicite en environnement local active le CSRF ; le JSON exact attendu détecte bien le callback initial inopérant. L'application de test est recréée entre les méthodes. |
| `UsersMigrationTest` | Le message d'exception, les colonnes et les données restantes sont vérifiés. Une simple `QueryException` après DDL ne suffirait pas à faire passer ce test. Le chemin de rollback autorisé n'y figure pas mais a été exécuté dans le contrôle isolé. |
| Déconnexion échouée | Le test attend le bandeau, le maintien de l'accueil connecté et de l'URL. Le code initial ne satisferait pas ces attentes. |
| Retour avec paramètres | L'URL complète est désormais vérifiée, ce qui lève le faux positif identifié lors de la première revue. |
| Déconnexion 401 et compte suivant | Ces tests vérifient les comportements acceptés autour de la correction. Tous les contrôles nominaux ne doivent pas nécessairement échouer sur l'ancien code pour être utiles. |
| Purge des données | Les tests vérifient la navigation, mais n'inspectent pas le contenu du cache des mutations. Ils laissent passer le nouveau constat de la section 3. |

Aucune campagne automatisée de mutation du code initial n'a été exécutée : les comparaisons avec l'ancien comportement reposent sur les reproductions du premier rapport et les nouvelles assertions, sans modifier les sources.

La surcharge de `Tests\TestCase::actingAs()` respecte la décision du responsable technique. Elle ajoute l'heure de connexion aux tests qui utilisent ce raccourci ; elle ne transforme pas ces tests en parcours navigateur. Pour tester l'absence d'heure, utiliser `be()` ; pour une heure particulière, la fixer après `actingAs()` afin qu'elle ne soit pas remplacée par le raccourci.

## 5. Points vérifiés sans remarque

- La recherche `User::findForLogin()` est partagée par le limiteur et le contrôleur, utilise les paramètres SQL et conserve l'exclusion des comptes supprimés.
- Une recherche supplémentaire du compte a lieu avant le contrôleur, y compris avant un refus 429. Ce coût est borné à une recherche sur e-mail/username indexés pour cette correction ; aucune régression fonctionnelle n'est reproduite. Aucun benchmark de capacité n'a été réalisé. Une optimisation de requêtes n'est pas exigée hors périmètre.
- Les compteurs de valeurs non textuelles sont distincts des compteurs de comptes ; la limite globale par IP reste appliquée comme prévu pour le Wi-Fi partagé.
- Le hash factice stocké en cache n'est pas un mot de passe de compte et n'est jamais utilisé pour accepter un identifiant inconnu. La clé suit le coût ; une suppression provoque sa régénération.
- Une première création après vidage du cache ajoute un calcul et une écriture ; des mots de passe déjà stockés peuvent conserver un coût antérieur après un changement de configuration. Le correctif supprime le saut de vérification, sans garantir une durée strictement identique pour toutes les situations. Aucune attaque temporelle n'a été mesurée.
- La session sans heure est maintenant refusée ; les connexions légitimes écrivent bien cette heure. Les tests de validité avant 12 h et de refus à 12 h restent présents.
- Le refus de rollback lit tous les comptes via `DB::table`, y compris ceux supprimés logiquement qui peuvent encore porter un e-mail nul.
- Les chemins de retour `//example.invalid` et `https://example.invalid`, injectés dans l'état du routeur du banc de contrôle, sont refusés au profit de `/pilotage`. Le chemin interne avec paramètres est conservé. Aucun vecteur de redirection externe exploitable n'a été établi.
- Une erreur réseau de déconnexion maintient l'état connecté et un avertissement. Les cas 204 et 401 conduisent à la connexion, et le compte suivant retrouve son propre accueil selon les tests frontend.
- Le rendu des autres erreurs n'est pas capturé indistinctement par le traitement 419 : 404 et 405 contrôlés localement ; le callback 403 reste placé avant celui de `HttpException` et le callback 419 retourne null pour les autres statuts.
- Les deux routes protégées actuelles restent dans le groupe `auth:sanctum` et `session.valid`. Aucun jeton d'accès n'est émis dans ce périmètre.
- Les tests du compte Entreprise partagé sont conservés : deux sessions distinctes restent actives pour les mêmes identifiants, les déconnexions sont indépendantes et la désactivation invalide les deux anciennes sessions à leur prochaine requête. Leur exécution dans la suite de cette contre-revue a réussi.
- Les décisions du 14/11 et les reports validés vers T-01, T-05, T-06, T-10 et T-12 restent respectés. Les styles de la maquette ne sont pas modifiés par les corrections logiques examinées.

## 6. Validation et limites

Environnement des contrôles : **PHP 8.5.12, MySQL 8.0.46, Node 24.21.0**. Aucun test SQLite. PHP 8.4 exact n'est toujours pas disponible dans cet environnement ; ENV-01 reste ouvert.

| Contrôle exécuté | Résultat |
|---|---|
| `php vendor/bin/pint --test` | Réussi |
| `php vendor/bin/phpstan analyse --no-progress` | Réussi : aucune erreur |
| `php artisan test --no-ansi` | Réussi : **178 tests, 461 assertions**, durée **1 011,80 s** (16 min 52 s), code de sortie 0 |
| `npm.cmd run lint` | Réussi |
| `npm.cmd run test` | Réussi : **14 tests**, 1 fichier |
| `npm.cmd run build` | Réussi, TypeScript et compilation Vite |
| `git diff --check -- backend frontend` | Réussi ; avertissements LF/CRLF uniquement |

Les 36 cas de la partie authentification, y compris les deux tests de sessions Entreprise partagées, ont réussi dans cette exécution complète. Aucun filtre d'authentification séparé n'a été relancé : le résultat communiqué ici est celui de la suite entière, avec ses 461 assertions. Les 14 tests frontend annoncés sont également confirmés par l'exécution indépendante.

Contrôles supplémentaires :

- Rejeu ciblé par le noyau HTTP Laravel avec cookies, sessions en base, cache database et MySQL strict. Tables isolées par le préfixe `cr16fb3b_`, puis supprimées. Contrôle final : **zéro table restante avec ce préfixe**. Les tables applicatives n'ont pas été modifiées par ces scénarios supplémentaires.
- Rejeu React/jsdom via les composants réels chargés par Vite : retour avec paramètres, échec de déconnexion, déconnexion 204, suppression d'une requête privée fictive, inspection des mutations et rejet des deux formes de chemin externe citées. Les réponses API y sont simulées ; ces contrôles ne sont pas présentés comme un parcours navigateur vers MySQL.
- Les contrôles Pint/PHPStan n'ont pas appliqué de correction. Les seuls artefacts produits hors de ce rapport sont les caches, journaux et builds usuels des outils.
- Le parcours navigateur avec le vrai backend annoncé par Claude le 16/11 n'a pas été rejoué dans un navigateur pendant cette contre-revue ; les vérifications HTTP/MySQL et React sont distinguées ci-dessus. Le contrôle visuel à 375/1023/1024 px de la revue initiale n'a pas été répété, les styles concernés n'ayant pas changé.
- Aucun test de charge, campagne statistique de temps de réponse ou contrôle du proxy de production n'a été réalisé. Les décisions de report correspondantes sont respectées.
- Le résultat d'une suite backend complète éventuellement exécutée par Claude n'a pas été fourni séparément dans cette demande. Le rapport se fonde sur l'exécution indépendante indiquée dans le tableau.

## 7. Verdict

**À fusionner après corrections mineures.**

Les sept constats initiaux sont corrigés dans le périmètre validé. La purge du cache des mutations après déconnexion reste à corriger et à couvrir par un test : l'état actuel conserve temporairement l'identité et le mot de passe du compte précédent en mémoire. Aucun code n'a été corrigé par cette contre-revue.
