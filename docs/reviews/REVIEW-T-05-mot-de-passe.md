# Revue indépendante — T-05 (JD-12) : activation et mots de passe

- **Date :** 17/11/2026, Africa/Casablanca.
- **Branche :** `feature/T-05-mot-de-passe`.
- **Base et HEAD :** `d0b92d4fa65c915f230493ff5834758b010afd9f`.
- **État :** développement non commité ; examen de `git diff develop` et des fichiers non suivis concernés.
- **Livrable :** ce rapport uniquement. Aucun correctif, fichier de test, commit ou push effectué.

## 1. Références et périmètre

Références : `AGENTS.md`, `CLAUDE.md`, plan T-05 **version 3**, deux revues du plan, décision « Mots de passe (T-05) » du 17 novembre, décisions des 14 et 16 novembre, CDC AUTH-01/02/03, RG-ENTR-06/07 et TRA-01, `CURRENT_TASK.md` et entrée T-05 de `PROGRESS.md`. Les trois maquettes ont été examinées lors de la revue du plan ; leurs écarts validés restent appliqués.

Code examiné : règle et liste de mots de passe ; migration et services de jetons/éligibilité/limitation ; contrôleurs, sessions et connexion partagée ; tâches d'envoi et d'audit, notification ; journal, observateur et modèle User ; routes, middleware et configuration modifiés ; pages, hooks et gestion de session frontend ; tests T-05 et adaptation de la signature du faux journal T-02.

Les décisions validées priment sur le CDC. La création des comptes, les écrans de réinitialisation administrative, le générateur et le reste du profil restent hors périmètre. Les exclusions demandées sont respectées : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.

## 2. Constats

### 1 — Un utilisateur chargé avant une réinitialisation peut encore changer le mot de passe avec l'ancien secret

- **Sévérité : Majeur.**
- **Fichier et lignes :** `backend/app/Http/Controllers/Auth/PasswordChangeController.php:49–63`.
- **Qualification : bug confirmé sur MySQL**, par entrelacement déterministe avec un objet utilisateur périmé ; pas par deux processus parallèles.
- **Références :** RG-AUTH-11/12 et contrat de verrouillage du plan v3.

**Scénario reproductible :**

1. Une requête de changement A est authentifiée et charge l'utilisateur avec son ancien hash.
2. Avant son changement, une réinitialisation B aboutit et ferme les sessions du compte.
3. A poursuit avec l'ancien objet et fournit l'ancien mot de passe comme `current_password`.

Le contrôleur vérifie ce mot de passe **avant** la transaction. Il lit ensuite une ligne fraîche sous verrou, mais **ignore le modèle retourné** et sauvegarde l'ancien objet.

**Résultat obtenu :** la vraie réinitialisation a répondu **200** ; l'ancien secret ne correspondait plus au hash en base. La requête utilisant l'ancien objet a néanmoins répondu **204**, puis son mot de passe a remplacé celui de la réinitialisation. Les contrôleurs et services réels ont été utilisés dans les tables isolées.

**Impact :** une requête déjà authentifiée peut écraser une réinitialisation après révocation, sans connaître le mot de passe devenu actuel. Le verrou ne protège pas une décision prise à partir d'un objet périmé. Le même emplacement expose le contrôle « nouveau différent de l'actuel » à des données périmées.

**Correctif minimal proposé :** récupérer et utiliser le modèle retourné par le verrou, puis vérifier le mot de passe actuel et le nouveau secret **sur ce modèle, dans la transaction**, avant toute écriture. Contrôler que le compte reste admissible. Après succès, synchroniser le modèle du garde avec la valeur enregistrée pour conserver correctement l'empreinte de session Sanctum. Ajouter le scénario de réinitialisation intercalée.

### 2 — Une réponse `/me` tardive peut rétablir l'identité précédente après la purge

- **Sévérité : Majeur.**
- **Fichier et lignes :** `frontend/src/auth/session.ts:11–20` ; appels dans `ActivationPage.tsx:55` et `ResetPasswordPage.tsx:53`.
- **Qualification : bug confirmé en mémoire**, avec le vrai helper et le vrai `QueryClient`.
- **Référence :** décision B et plan v3, purge au changement d'identité.

**Scénario reproductible :** une requête `['auth', 'me']` pour B est en cours. L'activation de A termine ; `clearClientSession` installe A. La réponse ancienne de B arrive ensuite.

**Résultat obtenu :** juste après le nettoyage, le cache contenait A ; après résolution de la requête en attente, il contenait **B**. Les requêtes privées hors préfixe `auth` avaient bien été retirées. C'est précisément la query d'identité conservée qui n'est pas annulée.

**Impact :** identité, rôle et informations du compte précédent peuvent réapparaître sous une session serveur appartenant à A. Cela ne démontre pas un contournement des autorisations backend, mais rompt l'isolation d'identité promise par le parcours et peut faire afficher le mauvais compte.

**Correctif minimal proposé :** annuler les requêtes en cours, **y compris `auth/me`**, et attendre leur annulation avant d'installer l'état final de session. Préserver ensuite les entrées d'authentification nécessaires aux composants. Ajouter une réponse `/me` différée qui arrive après un changement d'identité ; les tests avec réponses immédiates ne couvrent pas cette course.

### 3 — Un échec du journal de connexion laisse l'activation validée malgré une réponse 500

- **Sévérité : Majeur.**
- **Fichiers et lignes :** `backend/app/Http/Controllers/Auth/ActivationController.php:47–57`, `backend/app/Services/Auth/SessionOpener.php:25–31`.
- **Qualification : bug confirmé sur MySQL**, avec défaillance injectée uniquement sur `auth.login_succeeded`.
- **Référence :** plan v3, consommation tout ou rien et conservation du lien après erreur de journal.

**Scénario reproductible :** laisser réussir l'audit `account.activated`, puis faire échouer l'audit de connexion exécuté par `SessionOpener`.

**Résultat obtenu :** réponse rendue **500**, mais compte activé, nouveau mot de passe enregistré, **zéro jeton restant**, une ligne `account.activated` et aucune ligne `auth.login_succeeded`. Le garde avait déjà été authentifié dans le banc. Ce dernier résultat ne constitue pas un contrôle de persistance des cookies dans un navigateur.

La transaction de `consume()` a déjà été validée lorsque l'ouverture de session et son audit commencent. L'erreur tardive ne peut donc plus restaurer le jeton ni l'état du compte.

**Impact :** le lien est inutilisable après un échec annoncé, contrairement au contrat. Le compte et les audits sont partiellement modifiés ; l'utilisateur doit retrouver l'accès par un autre parcours.

**Correctif minimal proposé :** intégrer les écritures nécessaires de l'activation et ses audits obligatoires dans une unité transactionnelle cohérente, et n'exposer la nouvelle session qu'après succès. Nettoyer aussi l'état du garde/de session si une ouverture commencée doit être annulée. Ajouter un échec sur le **second** audit ; un échec limité à l'audit d'activation ne détecte pas ce cas.

### 4 — L'échec de mise en file du journal après remise est signalé puis abandonné

- **Sévérité : Majeur.**
- **Fichier et lignes :** `backend/app/Jobs/SendPasswordLink.php:83–88`.
- **Qualification : bug confirmé par injection d'un échec de dispatch**, notification simulée, sur tables isolées.
- **Références :** décision F, TRA-01 et étape 3 des reprises du plan v3.

**Scénario reproductible :** la remise simulée réussit ; l'insertion de la tâche `RecordLinkSent` échoue. Le catch appelle `report()` puis laisse `SendPasswordLink` terminer normalement.

**Résultat obtenu :** une notification remise, tâche d'envoi terminée sans exception, **aucune tâche de journal en attente**, aucune ligne de remise dans l'audit ; seul le signalement d'erreur technique a été appelé. Aucun SMTP ni incident réel de base n'a été provoqué : c'est le chemin d'exception du dispatch qui est exercé.

**Impact :** l'envoi n'est plus repris, ce qui évite le second e-mail, mais l'événement d'audit est perdu au lieu d'être confié à une reprise durable. Le journal technique ne remplace pas l'événement consultable exigé par F.

**Correctif minimal proposé :** assurer une persistance ou une reprise durable de l'étape d'audit lorsqu'elle ne peut pas être mise en file, en conservant l'information que la remise a déjà réussi. Ne pas relancer globalement l'envoi SMTP pour réessayer cet audit. Tester l'échec de **création de `RecordLinkSent`**, en plus de son échec d'exécution une fois déjà en queue.

### 5 — Un nouveau fragment sur une page déjà montée est effacé sans être traité

- **Sévérité : Mineur.**
- **Fichier et lignes :** `frontend/src/passwords/useLinkToken.ts:21–31` et `33–55`.
- **Qualification : bug confirmé en mémoire avec le vrai hook et React Router.**
- **Référence :** liens fragmentés et cohérence de navigation du plan v3.

**Scénario reproductible :** ouvrir `/activation` sans fragment, puis naviguer sur la même page montée vers `/activation#<nouveau-jeton>`, sans recharger le document. Même principe si un nouveau lien remplace un lien devenu invalide sur la même route.

**Résultat obtenu :** fragment retiré, statut toujours **`missing`**, **aucun appel** à `/activation/check`. L'état `initial` est figé au premier montage ; l'effet qui retire le fragment accepte les nouvelles locations, tandis que celui qui vérifie le lien continue d'utiliser l'ancienne valeur.

**Impact :** un lien valable reçu dans une page existante peut être perdu avant vérification. Le premier chargement d'un document avec fragment fonctionne ; le défaut concerne une navigation conservant le composant.

**Correctif minimal proposé :** prendre en compte chaque nouveau fragment non vide avant de le retirer, mettre à jour le jeton et son statut, et neutraliser une ancienne vérification encore en cours. Ajouter les transitions sans fragment → nouveau fragment et lien invalide → nouveau fragment, sur le même composant.

## 3. Vérification des trois points v3

| Point | État | Preuves et limites |
|---|---|---|
| Adresse recherchée distincte de la clé du limiteur | **Traité** | `PasswordLinkRequestController.php:40–49` transmet l'adresse validée ; `SendPasswordLink.php:111–112` recherche par cette adresse. `PasswordEmailThrottle.php:58–60` calcule une empreinte de `WEIGHT_STRING`, sans lire `users`. Contrôle du service réel : `jose/josé` partagent la clé, « a » latin et « а » cyrillique ont des clés distinctes. |
| Jeton conservé après erreur récupérable | **Traité pour les réessais du formulaire** | Appels directs hors TanStack ; les branches 422/429/réseau ne suppriment pas `link.token`, 410 l'invalide, succès le vide et ferme le parcours. Le message sans fragment est présent. Les tests communiqués couvrent ces reprises. La réception d'un autre fragment sur le composant reste le constat 5. |
| Étapes et reprises d'envoi | **Partiel** | Jeton créé dans le worker, `sendNow`, exception de transport sans message brut, nouvelle émission à la reprise ; `RecordLinkSent` séparé avec ses propres tentatives. Cette séparation évite de rejouer SMTP quand l'audit déjà en queue échoue. Le dispatch initial de cet audit peut toutefois être abandonné : constat 4. |

La limite du plan sur une réponse SMTP incertaine reste acceptée : cette revue ne réclame pas une remise externe « exactement une fois ».

## 4. Qualité des tests

Les tests couvrent utilement les bornes de la règle, les deux types de jetons, l'unicité SQL, l'expiration exacte, l'inéligibilité, le rollback du callback, les réponses publiques, le chiffrement de queue, les cinq rôles, deux clients entreprise, l'heure de connexion et les réessais frontend.

Les constats expliquent néanmoins comment la suite peut rester verte :

- `PasswordChangeTest` vérifie le mauvais mot de passe sur un modèle courant, mais n'intercale pas une réinitialisation après le chargement du titulaire. Le verrou présent ne prouve pas la fraîcheur du modèle effectivement sauvegardé.
- Le test de rollback de `PasswordTokenService` échoue **dans** le callback. Il ne couvre pas une défaillance d'activation située après son commit, dans `SessionOpener`.
- `SendPasswordLinkTest::test_remise_reussie_puis_journal_en_echec_aucun_second_envoi` fait réussir la mise en file, puis échouer l'exécution de `RecordLinkSent`. Il ne couvre pas une impossibilité de le mettre en file.
- Les tests d'identité frontend n'ont pas de réponse `/me` ancienne encore en attente après la purge.
- Les tests de lien montent les pages avec leur fragment initial ou sans fragment ; ils ne couvrent pas l'arrivée d'un nouveau fragment sur la même page montée.

Les tests de sessions avec cookies et nouvelle requête `/me` sont plus probants qu'une simple assertion sur le nombre de lignes SQL. Le test de session réinsérée prouve le rejet d'une ancienne empreinte, mais ne couvre pas la requête de changement périmée du constat 1, qui réécrit elle-même le mot de passe.

L'absence de tests réellement parallèles, déjà documentée par Claude, demeure une **limite de validation**. Elle n'est pas présentée comme un bug supplémentaire indépendant des constats reproduits.

## 5. Points vérifiés sans remarque

- **Jetons :** seul le SHA-256 est persistant ; index unique compte/type, rôle et état contrôlés ; verrou du compte avant relecture verrouillée du jeton ; expiration `now >= expires_at` ; rollback dans le callback ; révocation et purge prévues. Le périmètre de la transaction d'activation est l'exception du constat 3.
- **Éligibilité :** activation réservée à CME/Direction/administrateur non activés ; reset pour les quatre rôles autorisés activés ; entreprise, compte supprimé, désactivé ou sans rôle exclus. H1 est appliquée dans le worker.
- **Demandes publiques :** même traitement avant décision, validation, limites communes, une tâche chiffrée et 202 générique ; absence de recherche du compte dans le contrôleur. L'adresse de livraison est celle du compte retrouvé.
- **Limites :** 1/min par clé d'adresse, 10/min par IP, budget partagé oubli/renvoi ; limites de consommation et de changement distinctes. Aucun ajustement des seuils validés proposé.
- **Confidentialité :** lien fragmenté, corps POST, aucune adresse API avec jeton ; `ShouldBeEncrypted` sur l'envoi, notification immédiate créée dans le worker, pas de jeton dans les propriétés de la tâche ; `dontFlash`, middleware no-store et exception de lien avec no-store. Aucun secret réel utilisé dans les contrôles.
- **Mot de passe :** 10/64 caractères Unicode et 72 octets ; comparaison de liste sans modifier le secret, espaces préservés ; ressource versionnée avec source SecLists ; absence/vidage de liste non silencieux. Un mot de passe de 64 points de code et 67 octets, contenant un emoji, est accepté par la règle PHP **et** le schéma frontend testés en mémoire.
- **Sessions nominales :** rotation de `remember_token`, fermeture des autres sessions au changement et de toutes au reset ; conservation de l'heure et du modèle courant utilisée par Sanctum dans le cas nominal. Les tests communiqués vérifient réellement la requête suivante des deux clients.
- **Compte différent :** bannière, déconnexion frontend et garantie serveur ; auteur de la consommation = titulaire, sans déduire l'auteur de B. La protection du cache contre une réponse tardive reste à corriger.
- **Journal :** six actions explicites dans « Comptes », origine publique/admin, `visitor: true`, observateur neutralisé avec restauration dans un finally. La connexion après activation reste un événement distinct. La signature du double de test T-02 a été mise à jour pour le paramètre `visitor`, ce qui est une adaptation justifiée.
- **Services futurs :** `AccountActivationService` vérifie l'éligibilité et utilise `afterCommit` ; contrats de révocation et fermeture des sessions documentés pour T-10 à T-12. Les écrans futurs ne sont pas anticipés.
- **Interface et périmètre :** quatre écrans, routes de liens publiques, profil sous garde authentifiée, lien pour les cinq rôles, deux saisies de confirmation ; pas de dépendance ajoutée. Les contrôles visuels réels à 375 px sont ceux communiqués par Claude, pas une nouvelle recette effectuée par cet auditeur.

## 6. Questions au responsable technique

Aucun nouvel arbitrage métier n'est nécessaire pour les constats 1, 2, 3 et 5 : ils concernent les garanties déjà retenues. Le mécanisme de reprise durable de l'audit du constat 4 reste à choisir techniquement, en conservant la décision de ne pas renvoyer l'e-mail pour réessayer le journal.

ENV-01 reste ouvert pour PHP 8.4 exact. Les risques SMTP explicitement acceptés par le plan et les fonctionnalités reportées à T-10/T-12 ne sont pas rouverts.

## 7. Validation et limites

### Contrôles exécutés par cet auditeur

Environnement constaté : **PHP 8.5.12**, **MySQL 8.0.46**, collation **`utf8mb4_unicode_ci`**, **Node 24.21.0**. `docker compose up -d mysql` réussit, conteneur déjà actif ; `git diff --check develop` réussit.

Les contrôles PHP ont utilisé `jobdating_test` exclusivement, avec des copies de tables préfixées `review_t05_<pid>_`. Les copies conservent colonnes et index, mais pas les clés étrangères ni les déclencheurs ; aucune nouvelle garantie sur ces derniers n'est déduite des bancs. Les données sont fictives. Toutes les tables de contrôle ont été supprimées ; la recherche finale des tables préfixées est vide.

| Contrôle | Résultat |
|---|---|
| Réinitialisation réelle puis requête avec ancien objet utilisateur | Reset 200 ; ancien secret devenu faux en base ; changement périmé 204 et nouveau secret du reset écrasé. |
| Échec uniquement sur l'audit de connexion après activation | 500 ; compte activé, mot de passe enregistré, lien consommé, audit d'activation seul. |
| Remise simulée puis dispatch d'audit en échec | Envoi terminé normalement ; une notification, zéro job d'audit et zéro événement de remise. |
| Réponse `auth/me` différée après changement d'identité | A installé puis remplacé par B à la résolution de l'ancienne query. |
| Nouveau fragment sur le hook déjà monté | Fragment supprimé ; statut `missing` conservé ; zéro vérification envoyée. |
| Clé du limiteur avec le service réel | Équivalence accentuée respectée ; adresses latine/cyrillique distinguées. |
| Cas Unicode admissible PHP/frontend | Accepté des deux côtés. |

Les bancs PHP appellent les contrôleurs et services réels avec requêtes JSON ; ils ne rejouent pas tout le cycle HTTP. Le cas périmé rétablit volontairement l'objet d'une requête déjà authentifiée après le traitement réel d'une réinitialisation : **entrelacement contrôlé**, pas simultanéité de deux connexions SQL. Les erreurs d'audit et de dispatch sont injectées ; aucun incident réel de messagerie n'a été créé.

Les contrôles frontend utilisent les vrais modules compilés en mémoire, `QueryClient`, React Router et JSDOM selon le cas. Aucun fichier compilé ou test n'a été écrit dans le dépôt. Aucun SMTP réel, nouvelle recette navigateur complète ou suite applicative complète exécuté pendant cette revue.

### Résultats communiqués par Claude, non rejoués

- Pint réussi ; Larastan sans erreur.
- T-05 : **67 tests réussis** ; non-régression Auth/Audit : **63 tests réussis**.
- Suite backend complète : **304 tests réussis, 1 272 assertions**, environ 29 minutes, code de sortie 0. La première interruption sur la signature du faux journal T-02 a été corrigée avant cette relance complète ; elle n'est pas retenue comme défaut restant.
- Frontend : lint réussi, **43 tests réussis**, build et TypeScript réussis.
- Navigateur réel, backend, Mailpit et worker : parcours d'oubli/reset, refus du mot de passe courant puis réessai du même lien, connexion, profil à 375 px, changement, activation avec autre compte et journal en base vérifiés par Claude.

Ces résultats ont été repris de la demande actualisée. La suite complète n'a pas été relancée.

## 8. Verdict

**À corriger avant fusion.**

Corriger les décisions de changement prises sur un utilisateur périmé, la réapparition d'une ancienne identité en cache, l'activation partiellement validée après erreur et la perte de reprise d'audit. Le traitement d'un nouveau fragment est une correction mineure complémentaire. Les résultats nominaux communiqués ne couvrent pas ces scénarios confirmés.
