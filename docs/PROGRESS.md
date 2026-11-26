# Avancement

## 2026-11-12 — Initialisation technique

- Markdown initiaux analysés : README.md, CLAUDE.md et AGENTS.md.
- Absents au départ : docs/, CDC V2, présentation, tâche courante, décisions et suivi.
- Structure documentaire créée, sans inventer les références métier.
- Squelettes backend/ (Laravel 12) et frontend/ (React 19, TypeScript, Vite, Tailwind, React Router).
- Résolution Composer ciblée PHP 8.4 ; runtime local disponible : PHP 8.5.12.
- MySQL configuré, mode strict Laravel conservé ; aucune migration exécutée.
- Session/cache fichiers et queue synchrone pour démarrer sans base disponible.
- Préfixe API /api/v1 préparé, sans endpoint métier.
- Aucune authentification ni règle métier implémentée. Sanctum, Spatie et Reverb différés.

## Validation

Contrôles exécutés localement le 2026-11-12 :

- `composer validate --strict` : réussi.
- `php artisan test --no-ansi` : 2 tests du squelette réussis, 2 assertions. Ces tests ne couvrent aucune règle métier.
- `php vendor/bin/pint --test` : réussi.
- `php artisan route:list --except-vendor --no-ansi` : seule route applicative listée, page Laravel `/`. Fichier API vide, préfixe configuré.
- Configuration MySQL : `strict => true` vérifié.
- `npm.cmd run lint` : réussi.
- `npm.cmd run build` : vérification TypeScript et compilation Vite réussies.
- Installations Composer/npm : aucune vulnérabilité signalée par les audits automatiques lors de l'installation.

Versions installées : Laravel 12.72.0, React 19.3.1, Vite 8.3.8, Tailwind 4.3.5, React Router 7.18.5. Fichiers de verrouillage générés.

MySQL 8 et PHP 8.4 exact ne sont pas encore validés. Aucun test de connexion MySQL, de migration ou d'interface dans un navigateur n'a été effectué. La résolution Composer cible PHP 8.4.0, mais les tests PHP ont tourné sous PHP 8.5.12 et Node 24.21.0.

Le dossier initial n'est pas un dépôt Git : aucune comparaison git diff ni revue indépendante n'est possible à ce stade.

## 2026-11-12 — Revue du socle et corrections

Revue du socle par rapport au CDC V2 et à l'architecture, puis corrections sans fonctionnalité métier.

- Références déposées dans `docs/reference/` (CDC V2, présentation, architecture).
- Backend : fuseau `Africa/Casablanca` ; locale `fr` ; sessions en base, 720 min ; file d'attente et cache en base ; tests sur MySQL `jobdating_test` ; `composer.json` renommé ; `phpstan.neon` (Larastan niveau 6) ajouté.
- Backend en API seule : suppression de la page welcome, de `resources/js`, `resources/css`, `package.json` et `vite.config.js` ; le test d'exemple vérifie `/up`.
- Frontend : TypeScript en mode strict ; redirection Vite de `/api`, `/sanctum`, `/up` vers Laravel ; suppression des images par défaut.
- Nettoyage : dossier `Microsoft/` supprimé ; recommandations VS Code remplacées.
- Documentation : `DECISIONS.md` réécrit, `BACKLOG.md` créé, `CURRENT_TASK.md` = T-00, pipeline CI fourni dans `docs/ci.yml`.
- `.env` local aligné sur `.env.example` pour ces mêmes clés (fuseau, locale, sessions, file, cache).

Non exécuté : aucun contrôle n'a été relancé après ces corrections (PHP et réseau indisponibles dans l'environnement de revue). À faire avec T-00 : `composer update --lock`, installation des paquets, puis tous les contrôles. Points d'attention : la vérification TypeScript stricte peut faire apparaître des erreurs ; `php artisan test` exige désormais la base `jobdating_test`.

## 2026-11-12 — Dépôt GitHub et Docker de développement

- Dépôt `alaeloula/jobdating` : branches `main` et `develop` protégées (pull request obligatoire, discussions résolues, approbations annulées si nouveau commit, pas de push direct, de force push ni de suppression, administrateurs inclus). Branche par défaut : `develop`. La règle « CI verte obligatoire » sera ajoutée après la première exécution de la CI.
- Branche locale `feature/T-00-socle` créée.
- `docker-compose.yml` : MySQL 8.0 (port 3307, bases `jobdating` et `jobdating_test`) et Mailpit (SMTP 1025, interface 8025). `.env.example`, `.env` local et `phpunit.xml` alignés. Dans la CI, les variables du job (MySQL sur 3306, utilisateur root) restent prioritaires.
- Non exécuté : `docker compose up` et les contrôles restent à lancer sur le poste (T-00).

## 2026-11-12 — T-03 (JD-10) : rôles et permissions

- 5 rôles (`App\Enums\Role`) et 25 permissions (`App\Enums\Permission`), une par ligne de la matrice CDC 3.2.
- Administrateur : chaque permission attribuée explicitement, sans règle « peut tout », pour que ses propres restrictions (CDC 3.1) restent applicables. Validé par le responsable technique.
- Un seul rôle par compte (RG-ACC-06) : `User::setRole()` remplace le rôle ; `assignRole()` refuse un second rôle.
- `RolesAndPermissionsSeeder` idempotent, utilisable en production ; `DatabaseSeeder` ne crée plus de compte de test (EXI-SEC-11).
- Alias de middlewares `role` et `permission`.
- Tests : 125 cellules de la matrice (copie indépendante du CDC), cohérence matrice / code, rôle unique, seeder relancé.

Hors périmètre, à traiter dans les tâches concernées :
- droits limités au périmètre (🔸) : policies écrites avec chaque ressource (T-21, T-23, T-30…) ;
- APP-02 (l'apprenant consulte ses passages) : pas de ligne dans la matrice ; règle « ses propres passages, sans évaluation ni commentaire » dans la policy de T-40 (PC-04, PC-12). Validé par le responsable technique.

Vérification : syntaxe PHP contrôlée localement ; tests, Pint et Larastan exécutés par la CI de la pull request.

### Corrections après la revue indépendante (Codex, PR #3)

Trois constats majeurs confirmés, tous corrigés :
1. Plusieurs rôles attribuables à un compte non enregistré (Spatie diffère l'insertion) : attribution refusée tant que le compte n'est pas enregistré.
2. Contrôle du rôle unique non atomique : contrainte d'unicité `(model_id, model_type)` sur `model_has_roles` (garantie en base, y compris en concurrence) ; contrôles applicatifs dans une transaction avec verrou sur la ligne utilisateur.
3. `syncRoles()` refusé supprimait le rôle actuel : nombre de rôles vérifié avant tout retrait, remplacement dans une transaction.

Tests de régression ajoutés pour chacun des trois cas.

Remarques facultatives de la contre-revue Codex, non traitées :
- le test de la concurrence sur le verrou (deux connexions MySQL simultanées) n'est pas couvert ; la contrainte d'unicité en base reste la garantie ;
- règle pour les factories : créer le compte, puis attribuer le rôle (`afterCreating`, jamais `afterMaking`). Appliquée dans `UserFactory::withRole()` (T-04).

## 2026-11-14 — T-04 (JD-11) : connexion

Décisions du responsable technique (détail dans `DECISIONS.md`) :
- première connexion de l'apprenant non bloquante (écart avec RG-AUTH-13) ; mot de passe généré affiché une fois et envoyé par e-mail ; réinitialisation par l'administrateur pour tous les comptes ; fichier Excel des mots de passe d'import supprimé manuellement ; aucun e-mail aux entreprises. Le CDC sera mis à jour après validation ;
- frontend : TanStack Query, React Hook Form, Zod et `@hookform/resolvers` (validation partielle d'ARCH-02) ;
- accueil de la Direction : tableau de bord de pilotage, lecture seule.

Réalisé :
- migration `users` : `first_name`, `last_name`, `username` (unique), `email` facultatif, `phone`, `activated_at`, `last_login_at`, `deactivated_at`, suppression logique ; `name` et `email_verified_at` retirés ;
- `POST /api/v1/login`, `POST /api/v1/logout`, `GET /api/v1/me` ; Sanctum en mode session (`statefulApi`) ; erreurs JSON en français (401, 403, 404, 419, 422, 429) ;
- refus : identifiants faux (message générique, 422), compte supprimé (traité comme inexistant), désactivé, non activé, sans rôle (403, motif donné seulement après un mot de passe correct) ;
- limiteur `login` (RG-AUTH-01) ; middleware `session.valid` : compte désactivé déconnecté immédiatement, session limitée à 12 h depuis la connexion (RG-AUTH-02) ;
- `Role::homePath()` ; `DemoAccountsSeeder` (APP_ENV=local seulement, non appelé par `DatabaseSeeder`) ;
- `SANCTUM_STATEFUL_DOMAINS` ajouté (`.env.example`) pour le serveur Vite `localhost:5173` ;
- frontend : client API (cookie, CSRF, rejeu après 419, événements 401 / 403), gardes de route par rôle, mise en page avec déconnexion, écran « Connexion », accueils vides par rôle ; configuration Vitest (jsdom).

Hypothèses à confirmer :
- les comptes entreprise et apprenant devront être créés avec `activated_at` renseigné (T-11, T-12, T-13), sinon leur connexion est refusée comme « non activé » ;
- compte sans rôle : connexion refusée (« Votre compte n'a pas de rôle. Contactez l'administration. »), texte non prévu par le CDC ;
- les sous-titres de la maquette (« [Nom du JobDating] ») et le lien « Mot de passe oublié ? » arriveront avec l'événement courant (T-14) et T-05.

Points d'attention :
- les tests backend tournent sous PHP 8.5.12 (Herd n'a pas PHP 8.4) : ENV-01 reste ouvert ;
- tests lents (environ 5 s par test sur MySQL Docker) : piste à étudier séparément ;
- la table `personal_access_tokens` (jetons Sanctum) existe mais n'est pas utilisée (CDC 9.5 : pas de jetons) ;
- `php artisan serve` échoue sur le poste (« Unable to start curl module », PHP 8.5 de Herd) ; contournement par `php -S` depuis `backend/public` (voir `CURRENT_TASK.md`).

Vérification dans le navigateur (14/11, Laravel + Vite en local, compte de démonstration entreprise) : `/pilotage` sans session → connexion ; mauvais mot de passe → « Identifiants incorrects. » ; bon mot de passe → tableau de bord entreprise ; session conservée après rechargement ; `/login` connecté → accueil ; `/pilotage` → accueil entreprise + « Vous n'avez pas accès à cette page. » ; affichage mobile 375 px ; déconnexion → retour à la connexion.

Ajout validé après test fonctionnel (14/11) : photo du campus YouCode (reprise de l'ancienne application) à gauche de l'écran de connexion, à partir de 1024 px seulement. Droit d'utilisation de la photo à confirmer auprès de YouCode.

### Corrections après la revue indépendante (Codex, `docs/reviews/REVIEW-T-04-connexion.md`, 16/11)

Verdict de la revue : « À corriger avant fusion ». Les 7 constats ont été analysés avec le responsable technique (aucun ne contredit les décisions du 14/11), puis corrigés :
1. Limiteur contournable par une variante accentuée (`société` = `societe` pour MySQL) : compteur sur l'id du compte quand il existe, sinon sur l'identifiant normalisé sans accents (`User::findForLogin()` partagé avec la connexion).
2. Déconnexion échouée présentée comme réussie : l'état local n'est effacé qu'après confirmation du serveur (204, ou 401 = session déjà fermée) ; sinon message « La déconnexion a échoué… » et l'utilisateur reste sur la page.
3. Annulation de la migration `users` laissant la table à moitié modifiée : `down()` refuse avant toute modification s'il existe des comptes sans e-mail. Base de développement jetable (aucune donnée réelle, confirmé le 16/11) : l'ancien `name` n'est pas repris, hypothèse documentée dans la migration.
4. Paramètres de la page demandée perdus après connexion : un seul chemin de navigation (`GuestOnly`), chemins internes uniquement ; après une déconnexion volontaire, pas de « page demandée » pour le compte suivant.
5. Identifiant non textuel → erreur 500 : le limiteur ignore les valeurs non textuelles, la validation renvoie 422.
6. Erreur CSRF non traduite hors tests : rendu JSON français sur l'exception HTTP 419 (Laravel convertit `TokenMismatchException` avant les callbacks).
7. Durée de réponse révélant l'existence d'un compte : vérification d'un hash factice (même coût, mis en cache) pour un identifiant inconnu.

Question 2 de la revue : une session sans heure de connexion est désormais refusée ; `Tests\TestCase::actingAs()` renseigne cette heure (`be()` pour simuler l'absence).

Tests de régression ajoutés pour chaque point (backend `tests/Feature/Auth`, frontend `src/auth/auth.test.tsx`), plus un cycle complet connexion → `/me` → déconnexion sans `actingAs`.

### Contre-revue Codex (`docs/reviews/REVIEW-T-04-contre-revue.md`, 16/11)

Verdict : « À fusionner après corrections mineures ». Les 7 constats sont confirmés corrigés (178 tests backend, 461 assertions, et 14 tests frontend confirmés par l'exécution indépendante).

Nouveau constat corrigé : le cache des mutations TanStack conservait l'identifiant, le mot de passe saisi et l'identité renvoyée (le remplacement de `clear()` par `removeQueries()` ne purgeait plus les mutations). Correctif : envois de connexion et de déconnexion non conservés une fois l'écran fermé (`gcTime: 0`) ; après une déconnexion confirmée, mutations terminées supprimées. Tests ajoutés (cas 204 et 401) : 16 tests frontend.

Sessions parallèles d'un compte entreprise (PC-05) : règle confirmée par le responsable technique le 16/11 (voir `DECISIONS.md`). Le test `tests/Feature/Auth/CompanyConcurrentSessionsTest.php` (deux navigateurs, cookies séparés, sessions en base) la vérifie. Il est apparu dans le dossier pendant la revue, sans avoir été écrit par Claude ; contenu relu et conservé.

Reportés, à traiter dans les tâches concernées :
- T-01 (mise en ligne) : proxies de confiance et en-têtes transmis, pour que `$request->ip()` (limiteur) désigne bien le client ; HTTPS, cookies `Secure`, `SANCTUM_STATEFUL_DOMAINS` de production ;
- T-10, T-12 (désactivation par l'administrateur) : supprimer toutes les sessions du compte (table `sessions`), pour qu'une session restée inactive pendant la désactivation ne revienne pas après une réactivation.

## 2026-11-16 — T-06 (JD-13) : journal d'audit

Choix du responsable technique (détail dans `DECISIONS.md`) : écran de consultation inclus (export reporté à T-61) ; connexions journalisées, réussies et refusées ; protection dans le code et dans MySQL ; purge limitée au journal d'audit.

Réalisé :
- migration `audit_logs` (sans clé étrangère vers `users`, pour qu'une suppression de compte ne modifie jamais le journal ; libellés de l'auteur et de l'élément recopiés) et deux déclencheurs MySQL (aucune modification ; suppression seulement au-delà d'un an, tolérance d'un jour pour le fuseau) ;
- `AuditAction`, `AuditCategory`, modèle `AuditLog` (création seule), service `AuditLogger` (secrets exclus, CNIE masquée) ;
- branchements : observateur `UserAuditObserver` (création, modification, désactivation, réactivation, suppression, restauration ; la date de dernière connexion n'est pas journalisée comme modification ; un changement de mot de passe est noté « modifié » sans valeur), `User::syncRoles()` / `assignRole()` (changement de rôle, dans la même transaction), connexion (réussite, refus avec motif), blocage 429 (une fois par blocage), déconnexion ;
- commande `audit:purge`, planifiée chaque jour à 3 h ;
- API `GET /api/v1/audit-logs` (filtres catégorie, auteur, période, élément ; pagination bornée) et `GET /api/v1/audit-logs/filters`, permission `audit.view` ;
- frontend : écran « Journal d'audit » (catégories, auteur, période, tableau, détail avant / après, pagination), lien « Journal d'audit » dans l'en-tête pour l'administrateur ;
- `docker-compose.yml` : option `--log-bin-trust-function-creators=1` (sinon erreur 1419 à la création des déclencheurs, l'utilisateur `jobdating` n'ayant pas le droit SUPER et la journalisation binaire étant active). Conteneur relancé le 16/11, données conservées.

Points d'attention :
- production (T-01) : même option MySQL, ou création des déclencheurs par un compte administrateur ; planificateur Laravel à lancer (`schedule:run` chaque minute) pour la purge ;
- chaque création de compte et attribution de rôle écrit désormais dans le journal, y compris dans les tests et les seeders (auteur « système » = vide).

### Corrections après la revue indépendante (Codex, `docs/reviews/REVIEW-T-06-journal-audit.md`, 16/11)

Verdict : « À corriger avant fusion ». Constats analysés avec le responsable technique :
1. Variantes de noms de mot de passe non filtrées : filtre étendu (`pwd`, `passwd`, `mot de passe`, `mdp`, `pass`, `api key`, `secret`, `token`, `credential`), nom de clé normalisé (casse, accents, séparateurs), à toute profondeur ; la mention de changement de mot de passe devient une note (`note : Mot de passe modifié`). Corrigé.
2. CNIE non textuelle non masquée : **non corrigé, par décision du responsable technique** (masquage conservé pour le texte seulement).
3. Blocage global par IP journalisé une fois par identifiant : chaque limite a sa clé de déduplication (compte + IP, ou IP seule) ; champ `limite` ajouté. Corrigé.
4. Tolérance d'un jour du déclencheur : seuil exact d'un an ; la purge applicative attend un an et un jour. Corrigé (base de développement : migration du journal rejouée le 16/11).

Décisions prises à cette occasion : refus de connexion journalisés seulement pour un compte existant ; auteur « Système » hors requête web (pas d'adresse IP dans ce cas).

Tests de régression ajoutés : variantes de mot de passe, seuil exact et purge, blocage global avec plusieurs identifiants, identifiant inconnu non journalisé, auteur « Système ».

Points notés pour la suite :
- T-01 : compte MySQL de l'application distinct du compte des migrations, sans droit DROP (TRUNCATE contourne les déclencheurs) ; ne jamais annuler la migration du journal en production (`down()` supprime le journal) ;
- T-10 à T-13 et suivantes : les écritures silencieuses (`saveQuietly`) et les mises à jour de masse (`query()->update()`) ne passent pas par l'observateur ; les journaliser explicitement avec `AuditLogger`.

## 2026-11-16 — T-02 (JD-9) : référentiels

Préparation : plan rédigé à partir du CDC (ADM-09), revu deux fois par Codex (`docs/reviews/REVIEW-PLAN-T-02.md`, `REVIEW-PLAN-T-02-contre-revue.md`), version 3 validée par le responsable technique (option : derniers points intégrés sans troisième revue du plan, vérifiés par la revue du code). Décisions du 16/11 dans `DECISIONS.md` : compétences, secteurs, aucune suppression, filière d'une classe corrigible avec confirmation, type d'étiquette, code de filière définitif, journalisation, listes officielles saisies à l'écran.

Réalisé :
- migration des 8 tables (unicités, `active`, `position`, `seed_key`, clé classe → filière) ; modèles `App\Models\Referentials\*` ; énumérations `ReferentialType`, `EvaluationTagType` ;
- `ReferentialService` : chaque écriture et sa ligne d'audit dans une transaction ; ligne verrouillée (`FOR UPDATE`) et contrôles d'utilisation refaits sous ce verrou ; points d'extension `ClassUsage` et `TagUsage` (implémentations provisoires : 0 apprenant, étiquette non utilisée) ;
- `ReferentialController` et routes : `GET/POST /api/v1/referentials/{type}`, `PATCH /{type}/{id}`, `PUT /{type}/order` (`referentials.manage`), `GET /{type}/options` (tout connecté) ; aucune suppression ;
- confirmation de filière : `confirmer_changement_filiere` strictement booléen (sinon 422) ; 409 `class_in_use` avec le nombre recalculé par le serveur ; aucun champ modifié ni journal en cas de refus ;
- journal : catégorie « Référentiels » (7 catégories), actions explicites, libellé de la valeur (`HasAuditLabel`) ;
- `ReferentialsSeeder` (appelé par `DatabaseSeeder`) : clés fixes, rattachement technique des homonymes sans clé, créations journalisées « Système », relance sans effet ;
- frontend : écran « Référentiels » (8 onglets, ajout, modification, désactivation, ordre, étiquettes par type, code de filière en lecture seule, confirmation « N apprenants changeront de filière »), lien d'en-tête pour `referentials.manage` ; `ApiError.payload` pour lire `learners_count`.

Points d'attention :
- T-12 / T-13 / T-31 : brancher `ClassUsage` et `TagUsage` et prendre le même verrou de ligne lors des affectations ; écrire alors les tests de simultanéité sur MySQL ;
- tests : le point d'extension est résolu à chaque contrôle (pas d'injection dans le constructeur), car Laravel réutilise le contrôleur entre les requêtes d'un même test.

Revue du code par Codex (`docs/reviews/REVIEW-T-02-referentiels.md`, verdict « À fusionner après corrections mineures »), corrections :
- constat 1 : une modification partielle d'étiquette (type seul) vers un libellé déjà présent dans l'autre groupe donnait une erreur 500 ; l'unicité porte maintenant sur le couple type + libellé **effectif** (le champ absent reprend sa valeur actuelle), d'où un 422 avec un message clair ;
- constat 2 : `PUT /order` acceptait un objet JSON ; règle `list` sur `ids` (422) et positions comptées par le service, jamais tirées des clés reçues ;
- tests ajoutés : régression des deux constats, échec du journal qui annule la modification et le réordonnancement, déplacement d'une valeur dans l'écran (↑ / ↓).

## 2026-11-17 — T-05 (JD-12) : activation, oubli et changement du mot de passe

Préparation : plan rédigé à partir du CDC (AUTH-02, AUTH-03), revu par Codex (`docs/reviews/REVIEW-PLAN-T-05.md`, « Plan à revoir »), version 2, contre-revue (`REVIEW-PLAN-T-05-contre-revue.md`, 3 points), version 3 validée par le responsable technique sans troisième revue du plan (les 3 points sont à vérifier dans la revue du code). Décisions du 17/11 dans `DECISIONS.md` (« Mots de passe (T-05) »).

Réalisé :
- règle `App\Rules\StrongPassword` (10 à 64 caractères, au plus 72 octets à cause de bcrypt, refus des 10 000 mots de passe courants) ; liste `backend/resources/security/common-passwords.txt` : SecLists, `Passwords/Common-Credentials/10k-most-common.txt`, commit `913b327`, licence MIT ; liste absente → erreur (jamais de validation silencieuse) ;
- table `password_tokens` et `PasswordTokenService` : SHA-256 seul, unicité `(user_id, type)`, verrou du compte puis du lien, relecture sous verrou, tout ou rien, expiré si `now >= expires_at` (horloge PHP), commande planifiée `password-tokens:purge` (03:15) ;
- demandes publiques `POST /password/forgot` et `POST /activation/resend` : validation, limites (1 / min par adresse, clé calculée par MySQL avec `WEIGHT_STRING` sans lire `users` ; 10 / min par IP ; budget commun), une tâche chiffrée `SendPasswordLink`, 202 identique ; la tâche cherche le compte, décide, crée le jeton et envoie aussitôt (`sendNow`) ; reprise avec nouveau jeton seulement en cas d'échec de remise ; journal « lien remis » par la tâche séparée `RecordLinkSent` (jamais de second envoi) ; `account.link_email_failed` après 3 échecs de remise ;
- activation `POST /activation/check` et `POST /activation` (connexion automatique par `SessionOpener`, désormais partagé avec `LoginController`) ; réinitialisation `POST /password/reset/check` et `POST /password/reset` (toutes les sessions fermées, pas de connexion automatique) ; jeton toujours dans le corps, jamais dans une adresse ; `Cache-Control: no-store` ; 10 / min par IP ; un autre compte connecté est déconnecté par le serveur ;
- changement `PUT /me/password` (5 rôles, mot de passe actuel exigé, nouveau différent de l'actuel, 5 / min par compte, autres sessions fermées, identifiant de session renouvelé, heure de connexion conservée, liens de réinitialisation annulés) ;
- journal : 6 actions (« Comptes »), `AuditLogger::record(..., visitor: true)` pour « Visiteur non connecté », observateur neutralisé pendant ces opérations (`User::withoutAccountAudit`) ;
- e-mails en français (`PasswordLinkNotification`, `lang/fr.json` pour le pied de page), lien `{FRONTEND_URL}/activation#jeton` ou `/reinitialiser#jeton` (nouvelle variable `FRONTEND_URL`) ;
- frontend : lien « Mot de passe oublié ? », lien « Recevoir un nouveau lien d'activation » après `account_not_activated`, pages `/activation`, `/mot-de-passe-oublie`, `/reinitialiser`, `/profil` ; jeton lu dans le fragment, retiré de l'adresse, gardé seulement dans l'état du composant (conservé après 422 / 429 / réseau) ; aucun jeton ni mot de passe dans le cache TanStack ; bandeau « Vous êtes connecté en tant que … » ; purge des données client au changement d'identité (`auth/session.ts`, aussi utilisé par la déconnexion).

Vérification navigateur (17/11, comptes fictifs `recette.apprenant@` et `recette.cme@jobdating.test` créés dans la base de développement) : oubli → e-mail en français dans Mailpit → lien → mot de passe courant refusé puis accepté avec le même lien → connexion avec le nouveau mot de passe ; « Mon profil » à 375 px, changement du mot de passe, session conservée ; activation du compte CME alors que l'apprenant était connecté (bandeau, déconnexion, accueil CME) ; journal vérifié en base.

Points d'attention :
- en local, `php artisan queue:work` doit tourner pour envoyer les e-mails ; la première remise à Mailpit a pris 26 s sur ce poste (connexion SMTP lente de PHP 8.5 sous Windows, comme `php artisan serve`, ENV-01) ;
- tests de simultanéité réelle (deux connexions MySQL en parallèle) non écrits : les tests couvrent les verrous et l'unicité de façon séquentielle ; à faire si un banc multi-processus est mis en place ;
- contrats pour T-10 à T-12 : voir `CURRENT_TASK.md`.

Contrôles (17/11) : Pint et PHPStan sans erreur ; suite backend complète **304 tests réussis, 1272 assertions** (code de sortie 0) ; frontend : lint, 43 tests et build réussis. Une première exécution complète s'était arrêtée sur une erreur fatale (faux journal du test T-02 sans le nouveau paramètre `visitor` de `AuditLogger::record`) : signature du test corrigée, suite relancée en entier.

Revue du code par Codex (`docs/reviews/REVIEW-T-05-mot-de-passe.md`, verdict « À corriger avant fusion », 5 constats). Décision du responsable technique (17/11) : corriger tout de suite ce qui a un impact sur les développements suivants, reporter les cas rares sans impact dans `docs/ERREURS_A_CORRIGER.md` (tâche T-49, avant la recette).
- constat 1 (Majeur), **corrigé** : le changement depuis le profil vérifiait le mot de passe actuel sur l'objet chargé en début de requête ; toutes les vérifications portent maintenant sur la ligne relue sous verrou, dans la transaction ; le modèle du garde reçoit les valeurs enregistrées (empreinte de session) ; test d'une réinitialisation intercalée ;
- constat 3 (Majeur), **corrigé** : activation et ouverture de session (avec son journal) dans la même transaction ; en cas d'échec, tout est annulé, la session commencée est refermée et le lien reste utilisable ; test d'échec du seul journal de connexion ;
- constats 2, 4 et 5 : reportés (E-01, E-02, E-03 de `ERREURS_A_CORRIGER.md`).

Décision du 17/11 « Compte entreprise : changement du mot de passe sans déconnexion des autres recruteurs » (`DECISIONS.md`) : `SessionRevoker::afterPasswordChange()` ferme les autres sessions (RG-AUTH-12) sauf pour un compte entreprise, dont les autres sessions sont conservées avec l'empreinte du nouveau mot de passe (clé `password_hash_web` mise à jour dans leur contenu, sinon le middleware `AuthenticateSession` de Sanctum les déconnecterait) ; journal « Autres sessions conservées (compte entreprise) » ; la réinitialisation (`closeAll`) ferme toujours tout, entreprise comprise. Écran « Mon profil » : message adapté pour l'entreprise. Tests : un rôle non entreprise (autre session fermée), entreprise (autres recruteurs toujours connectés, nouveau mot de passe seul valable), fermeture complète toujours valable pour une entreprise. Limite : une requête d'un autre recruteur en cours au même instant peut réécrire sa session avec l'ancienne empreinte et le déconnecter (rare, il se reconnecte).

Contrôles après les corrections de la revue et la décision entreprise (17/11) : Pint et PHPStan sans erreur ; suite backend complète **308 tests réussis, 1302 assertions** (code de sortie 0) ; frontend : lint, 43 tests et build réussis. Durée locale de la suite ≈ 29 min contre ≈ 2 min pour tout le pipeline en CI : lenteur propre au poste (Windows, PHP 8.5, MySQL dans Docker), à mesurer avec ENV-01.

Contre-revue Codex (`docs/reviews/REVIEW-T-05-contre-revue.md`, « À corriger avant fusion ») : constats 1 et 3 confirmés corrigés ; E-01 à E-03 jugés fidèles et leur report accepté ; **nouveau constat 6 (Majeur), corrigé** : la conservation des sessions entreprise mettait à jour l'empreinte de toutes les autres sessions, y compris une session révoquée par une réinitialisation puis réécrite tardivement, qui redevenait valable. `SessionRevoker` ne met plus à jour que les sessions dont l'empreinte correspond au mot de passe en vigueur juste avant le changement (formats HMAC et brut de Sanctum, comparaison `hash_equals`) ; les sessions périmées ou illisibles sont supprimées ; l'ancien hachage reste en mémoire, jamais journalisé. Test : réinitialisation → réécriture tardive → changement par un recruteur → l'ancien client reste refusé, le collègue reste connecté.

Mesure de la lenteur des tests locaux (17/11) : chaque requête SQL prend ≈ 44 ms en test contre 0,5 ms attendu ; cause confirmée : requêtes préparées réelles (réglage Laravel, 2 échanges par requête) entre PHP sous Windows et MySQL dans Docker (délai réseau d'environ 40 ms sur le second échange) ; mêmes requêtes en mode « émulé » (1 échange) : 0,5 ms. Le seeder des rôles et permissions (105 requêtes) coûte à lui seul ≈ 4,7 s par test. Piste : faire tourner PHP dans WSL ou Docker à côté de MySQL (ENV-01), plutôt que changer le mode des requêtes (comportement des types différent de la CI).

Contrôles après la correction du constat 6 (17/11) : Pint et PHPStan sans erreur ; suite backend complète **309 tests réussis, 1312 assertions** (code de sortie 0).

## 2026-11-18 — ENV-01 : tests du backend dans Docker (PHP 8.4)

Cause mesurée le 17/11 : chaque requête des tests vers MySQL dans Docker prenait ≈ 45 ms depuis PHP sous Windows (requêtes préparées en deux échanges, délai réseau ≈ 40 ms) ; suite complète ≈ 29 min.

Réalisé (branche `chore/ENV-01-php-docker`) :
- `docker/php/Dockerfile` : image `php:8.4-cli`, extensions de la CI (`pdo_mysql`, `mbstring`, `intl`, `zip`), Composer, OPcache en ligne de commande sans revérification des fichiers (chaque `docker compose run` est un nouveau processus) ;
- `docker-compose.yml` : service `php` (profil « outils », ne démarre pas avec `up`), dossier `backend/` partagé, MySQL joint par le réseau Docker (`mysql:3306`, prioritaire sur `.env` et `phpunit.xml`).

Mesures dans Docker : requête préparée ≈ 1 ms (au lieu de 45) ; test courant ≈ 0,4 s (au lieu de 5,7) ; premier test ≈ 28 s (création de la base de test, une seule fois) ; **suite complète 309 tests réussis, 1312 assertions, en 308 s (≈ 5 min, 6 min avec le démarrage du conteneur) au lieu de 29 min** ; Pint réussi ; PHPStan sans erreur (≈ 4 min dans le conteneur, à cause du dossier partagé avec Windows : il peut rester lancé sous Windows).

Commandes :
- `docker compose run --rm php php artisan test`
- `docker compose run --rm php vendor/bin/pint --test`

ENV-01 : réglé pour les tests (PHP 8.4, comme la CI) ; reste ouvert pour le site local, encore en PHP 8.5 sous Windows.

CI (18/11) : audit npm séparé en deux étapes (production bloquant, outils de développement informatif), à cause de la faille GHSA-fc5q-39xc-vjpf de `picomatch` sans correctif (décision technique du 18/11 dans `DECISIONS.md`).

## 2026-11-18 — T-10 (JD-15) : gestion des comptes de l'équipe

Mode de revue choisi par le responsable technique : pas de revue du plan, une revue du code par Codex. Réponses du 18/11 : écran limité aux comptes CME, Direction et administrateur (entreprises T-11, apprenants T-12) ; suppression et restauration incluses (absentes de la maquette) ; on ne peut ni supprimer, ni désactiver, ni changer le rôle de son propre compte, et il reste toujours au moins un administrateur actif ; réinitialisation par l'administrateur (mot de passe généré affiché une fois, avec « Copier », et envoyé par e-mail) ; photo hors périmètre.

Réalisé :
- `StaffAccountService` : création (compte non activé, mot de passe inutilisable, lien d'activation après validation de la transaction), modification (rôle unique), désactivation / réactivation, suppression logique / restauration, renvoi du lien, réinitialisation ; ligne du compte relue sous verrou ; protections (propre compte, dernier administrateur actif, administrateurs verrouillés pendant le contrôle) ; désactivation et suppression : sessions fermées et liens annulés (RG-ACC-05) ;
- `PasswordGenerator` : 16 caractères sans caractères ambigus, conformes à `StrongPassword` (réutilisable par T-11 / T-12) ; `GeneratedPasswordNotification` mise en file chiffrée, après validation ;
- API `/api/v1/users` (permission `users.manage`, administrateur seul) : liste paginée (25) avec recherche, filtres rôle et statut (« Supprimés » à part), création, modification, désactivation, réactivation, suppression, restauration, renvoi du lien, réinitialisation (`Cache-Control: no-store`) ; un compte entreprise ou apprenant est traité comme inexistant (404) ;
- journal : actions existantes (création, modification, rôle, désactivation, réactivation, suppression, restauration) ; réinitialisation : `account.password_reset` avec l'administrateur comme auteur, note « Réinitialisé par l'administrateur », jamais le mot de passe ;
- écran « Comptes CME et Direction » (`/comptes`, maquette admin-10) et lien « Comptes » pour `users.manage` ; mot de passe réinitialisé gardé hors du cache TanStack.

Contrôles (18/11) : tests T-10 20 réussis (Docker) ; frontend lint, 50 tests et build réussis ; navigateur avec le compte administrateur de démonstration : liste, création d'un compte fictif `recette.direction@jobdating.test` (e-mail d'activation dans Mailpit), réinitialisation du compte fictif `recette.cme@` (mot de passe affiché une fois, e-mail reçu).
Suite backend complète dans Docker : **329 tests réussis, 1503 assertions**, ≈ 4 min ; Pint et PHPStan sans erreur.

Revue du code par Codex (`docs/reviews/REVIEW-T-10-utilisateurs.md`, « À corriger avant fusion »), toutes corrigées (choix du responsable technique : pas de contre-revue) :
- constat 1 (Majeur) : une invitation d'administrateur non activée comptait comme administrateur actif ; seuls les administrateurs activés, non désactivés, non supprimés comptent ; retirer une invitation n'est plus bloqué ; test ;
- constat 2 (Mineur) : interblocage MySQL quand deux administrateurs agissent l'un sur l'autre ; transactions rejouées jusqu'à 3 fois (`DB::transaction(…, 3)`), règle réévaluée sur l'état relu ; non testé en parallèle réel (deux processus) ;
- constat 3 (Mineur) : un échec de chargement affichait « Aucun compte. » ; message « La liste des comptes n'a pas pu être chargée » et bouton « Réessayer » ; test ;
- constat 4 (Mineur) : la recherche « 0 » était ignorée (`empty()`) ; test.

Contrôles : tests T-10 22 réussis ; suite complète dans Docker **331 tests réussis, 1508 assertions** (≈ 4 min) ; Pint, PHPStan, lint, 51 tests frontend et build réussis.

## 2026-11-19 — T-11 (JD-16) : entreprises

Préparation : plan (`docs/tasks/PLAN-T-11.md`) revu par Codex (`docs/reviews/REVIEW-PLAN-T-11.md`, « Plan à revoir » : version des fiches, atomicité du logo, propositions d'identifiant, valeurs inactives, identité du compte), version 2 validée par le responsable technique sans contre-revue du plan. Décisions du 19/11 : événement et emplacement reportés à T-14 (emplacement facultatif) ; CME : profils recherchés (et emplacements dans T-14) ; logo inclus ; import → T-13, export → T-61 ; désactivation sans suppression ; présentation modifiable par l'entreprise ; RG-ENTR-08 remplace PC-14 (`DECISIONS.md`) ; « Fiche imprimée » = confirmation par l'administrateur.

Réalisé :
- tables `companies`, `company_filiere`, `company_competence`, `company_recruiters` ; modèles `Company`, `CompanyRecruiter` ;
- permissions `companies.manage` (administrateur) et `companies.profiles.manage` (administrateur, CME) ; matrice mise à jour ;
- `UsernameSuggester` (RG-ENTR-04 : nom simplifié, chiffre en cas de doublon, comptes supprimés compris, toujours 3 à 50 caractères) ; `CompanyService` : création (compte entreprise activé, mot de passe généré renvoyé une fois avec la version de la fiche, aucun e-mail), modification (secteur inactif conservé, nouveau secteur actif exigé), logo (validé avant tout effet, stocké avant la transaction, supprimé si elle échoue, ancien supprimé après validation ; stockage **privé** servi par `GET /companies/{id}/logo` aux utilisateurs autorisés, au lieu d'un stockage public : aucun lien symbolique à créer), désactivation / réactivation (sessions fermées), réinitialisation (toutes les sessions fermées, nouvelle version, aucun e-mail), confirmation d'impression liée à la version courante (409 pour une ancienne fiche, idempotente), profils recherchés (nouvelle valeur active exigée, association devenue inactive conservée) ;
- API `/api/v1/companies` (liste, fiche, proposition d'identifiant, création multipart, modification, logo, désactivation, réactivation, réinitialisation, confirmation d'impression, profils) et `/api/v1/me/company` (présentation de l'entreprise connectée) ;
- journal : catégorie « Entreprises » (8 catégories) et actions `company.created`, `company.updated`, `company.profiles_updated`, `company.credentials_printed` ; réinitialisation journalisée sur le compte avec l'administrateur comme auteur ;
- frontend : écran « Entreprises » (`/entreprises`, administrateur et CME), formulaire (maquette admin-04 sans événement), fiche de connexion imprimable (admin-05, feuille d'impression limitée à la fiche), profils recherchés avec le bandeau et recruteurs en consultation, « Présentation » dans « Mon profil » de l'entreprise ; `apiRequest` accepte un `FormData` ;
- `DemoAccountsSeeder` : fiche « Entreprise Démo » pour le compte `entreprise-demo`.

Passage de relais à T-14 : les entreprises créées par T-11 (dont la démonstration) n'ont aucune participation ; T-14 doit permettre leur première participation et garder l'emplacement facultatif. Le critère ADM-02 « apparaît parmi les participants » est différé.

Contrôles (19/11) : tests T-11 23 réussis (Docker) ; frontend lint, 56 tests et build réussis ; navigateur (administrateur de démonstration) : création d'une entreprise fictive « Recette Société Fictive » (identifiant `recette-societe-fictive` proposé), fiche affichée, impression confirmée (« Imprimée »).
Suite backend complète dans Docker : **364 tests réussis, 1719 assertions** (≈ 5 min) ; Pint et PHPStan sans erreur.

Revue du code par Codex (`docs/reviews/REVIEW-T-11-entreprises.md`, 3 constats). Décisions du responsable technique (19/11) :
- constat 1 (nettoyage des logos et transaction englobante) : corrigé ; l'ancien logo est supprimé après le commit final (`DB::afterCommit`), le nouveau est supprimé si la transaction englobante est annulée (`DB::afterRollBack` enregistré avant la transaction interne) ; utile pour T-13 (import dans une transaction) ;
- constat 2 (état « impression confirmée » réutilisé par la fiche suivante) : corrigé, la fiche est remontée pour chaque version ;
- constat 3 (profils ignorés à la création), option (a) : profils refusés à la création (422), saisis ensuite par « Profils recherchés » ; plan mis à jour.
Après la recette du responsable technique : logo rond de 48 px (ou initiales) et profils recherchés en badges dans la liste.
Contrôles après corrections (19/11) : Pint et PHPStan sans erreur ; frontend lint, 58 tests et build réussis ; suite backend complète dans Docker : 366 tests : 365 réussis, 1 échec (`test_profils_refuses_a_la_creation` : la règle `prohibited` accepte une liste vide) ; correction par la règle `missing` (champ refusé dès qu'il est envoyé, même vide), puis `tests/Feature/Companies` relancé : **25 tests réussis** (suite complète non relancée après ce changement limité à la validation de `store`). Contre-revue Codex : `docs/reviews/PROMPT-CODEX-CONTRE-REVUE-T-11.md`.

Contre-revue Codex (`docs/reviews/REVIEW-T-11-contre-revue.md`, « À fusionner après corrections mineures ») : constats 2 et 3 corrigés, constat 1 partiellement corrigé. Décisions du responsable technique (19/11) :
- constat 4 (fichier orphelin avec deux niveaux de transaction englobante), option (a) : la double suppression après un échec interne est corrigée (suppression du nouveau fichier exécutée une seule fois, partagée entre le `catch` et le rappel d'annulation) ; le cas « niveau intermédiaire validé puis niveau supérieur annulé » est un **passage de relais à T-13** (ci-dessous) ;
- constat 5 : initiales affichées si le logo ne se charge pas ;
- constat 6 : contraste renforcé de « Aucun profil recherché » et des badges inactifs (`slate-600`) ;
- défaut trouvé pendant la recette du responsable technique (non signalé par les revues) : le message d'erreur du logo restait affiché après le choix d'un autre fichier ; il disparaît désormais dès qu'un nouveau fichier est choisi.

Passage de relais à T-13 (import des entreprises) : si l'import appelle `CompanyService::create()` ou `replaceLogo()` sous **deux** niveaux de transaction englobante, et que le niveau intermédiaire est validé puis le niveau supérieur annulé, le nouveau logo reste orphelin sur le disque privé (données et ancien logo corrects). T-13 doit soit appeler le service sous un seul niveau englobant, soit étendre le nettoyage, et ajouter un test de ce scénario (contre-revue Codex T-11, constat 4).

Contrôles après la contre-revue (19/11) : Pint et PHPStan sans erreur ; frontend lint, **60 tests** et build réussis (les 2 nouveaux tests échouent sans les corrections) ; suite backend complète dans Docker : **367 tests réussis, 1741 assertions** (≈ 4 min 30).

## 2026-11-19 — Navigation de l'interface (branche `chore/ui-navigation`, hors backlog)

Demande du responsable technique après la recette de T-11 : rendre la navigation plus lisible. Décisions du 19/11 : suivre les maquettes plutôt que des menus groupés (admin-01, entreprise-01, entreprise-ordi-01, apprenant-01) ; seuls les écrans déjà développés apparaissent (chaque tâche ajoutera son lien) ; icônes dessinées en SVG, **sans nouvelle dépendance** ; libellés des maquettes (« Entreprises participantes », « Comptes CME et Direction », « Profil entreprise »).

Réalisé (frontend seulement, aucun changement de droits ni d'API) :
- administrateur, CME, Direction : barre latérale (« JobDating » ramène à l'accueil ; nom, rôle, « Mon profil » et « Se déconnecter » en bas) ; sur mobile, ouverture par le bouton ☰, fermeture par un lien, « Fermer le menu », Échap ou un clic à côté ;
- entreprise : barre latérale avec icônes sur ordinateur, onglets en bas sur mobile (même navigation) ; déconnexion en haut sur mobile, en bas de la barre sur ordinateur ;
- apprenant : onglets en bas sur toutes les tailles (aucune maquette sur ordinateur : hypothèse validée par le responsable technique) ; « Mon profil » et déconnexion dans le menu du compte (initiales en haut à droite), car les maquettes ne les montrent pas ;
- « Entreprises » : la colonne de droite n'apparaît que lorsqu'un formulaire ou une fiche est ouvert.

Hypothèses : sous-titres « Espace CME » et « Espace Direction » (seul « Administration » figure sur la maquette admin-01) ; le titre de la page reste « Entreprises » alors que le lien s'appelle « Entreprises participantes » (la notion de participation arrive avec T-14). Hors périmètre : encadré de l'événement et compteur de « Mes passages » (entreprise-ordi-01), en attente de T-14 et des passages.

Contrôles (19/11) : frontend lint, **70 tests** (dont 10 de navigation : liens par rôle, lien actif, menu mobile, menu du compte) et build réussis ; navigateur (administrateur de démonstration) : barre latérale à 1 280 px, menu ☰ à 766 px. Espaces entreprise et apprenant non vérifiés dans le navigateur (couverts par les tests).

Revue du code par Codex (`docs/reviews/REVIEW-UI-NAVIGATION.md`, « À fusionner après corrections mineures »). Décisions du responsable technique (19/11) :
- constat 1 (menu ouvert qui cache le message d'échec de déconnexion) : corrigé, le menu ☰ ou le menu du compte se referme en cas d'échec et le focus revient à son bouton ;
- constat 2 (menu du compte de l'apprenant rouvert après un aller-retour entre pages) : corrigé, les menus sont réellement refermés à chaque changement de page ;
- débordement horizontal de « Entreprises » à 375 px (défaut antérieur, signalé par Codex) : reporté dans `docs/ERREURS_A_CORRIGER.md` (E-04, T-49).

Contrôles après corrections (19/11) : frontend lint, **72 tests** (dont 12 de navigation ; les nouveaux scénarios échouent sans les corrections) et build réussis.

## 2026-11-19 — T-12 (JD-17) : apprenants

Préparation : plan (`docs/tasks/PLAN-T-12.md`) revu par Codex (`docs/reviews/REVIEW-PLAN-T-12.md`, « Plan à revoir » : raccord `ClassUsage`, CNIE dans les filtres journalisés, liens après changement d'e-mail, contrat des actions en masse) ; version 2 contre-revue (`docs/reviews/REVIEW-PLAN-T-12-contre-revue.md`, « Plan validé après corrections mineures ») ; version 2.1 (ancrage de la règle CNIE, action fixée par la route, cas 0 / 0) appliquée sans nouvelle contre-revue. Décisions du 19/11 dans `DECISIONS.md` (« Apprenants (T-12) », COMP-01 tranché).

Réalisé :
- tables `learners` (CNIE unique, campus, promotion, classe, identifiant CME unique facultatif) et `learner_competence` (sans niveau) ; modèle `Learner` ; règle `App\Support\Cnie` (espaces retirés, majuscules, `\A[A-Z0-9]{5,30}\z`) ;
- `LearnerClassUsage` branché à la place de `NoClassUsage` (supprimé) : le changement de filière d'une classe compte toutes les fiches (actives, désactivées, supprimées) ;
- permission `learners.competences.manage` (administrateur, CME) ; routes `/learners` limitées aux **rôles** administrateur, CME et Direction (l'entreprise, qui a `learners.view` pour T-23, reçoit 403) ;
- `LearnerService` : création (compte activé, mot de passe affiché une fois et envoyé par e-mail de **création** après la validation finale), modification (référentiel inactif conservé, nouveau actif exigé ; liens annulés si l'e-mail change), réinitialisation (sessions fermées, e-mail de réinitialisation), désactivation / réactivation, suppression logique / restauration (sessions et liens coupés, désactivation conservée), compétences ; **ordre de verrouillage classes → compte → fiche**, opération recommencée si la classe a changé pendant l'attente ;
- actions en masse : sélection par identifiants **ou** filtres, comptage `{ selected, eligible }`, exécution avec le nombre confirmé, 409 sans écriture ni journal, 0 / 0 sans effet, limite 2 000, action fixée par la route ; journal par compte et synthèse sans recherche libre ;
- recherche par `POST /learners/search` (CNIE hors des adresses) ; **CNIE complète pour l'administrateur, la CME et la Direction** (liste, fiche, recherche), **jamais pour une entreprise** (décision du 19/11 prise pendant la revue du code, qui remplace le masquage et la journalisation de chaque consultation : permission `learners.view_cnie` donnée à la CME et à la Direction) ; téléphone et identifiant CME : administrateur seulement ;
- journal : catégorie **« Apprenants »** (9 catégories) ; actions `learner.created`, `learner.updated`, `learner.competences_updated`, `learner.bulk_deactivated`, `learner.bulk_competence_added` ; CNIE toujours masquée dans le journal ;
- `GeneratedPasswordNotification` à deux motifs (création, réinitialisation) ;
- frontend : écran « Apprenants » (`/apprenants`, lien après « Entreprises participantes ») : filtres (options historiques), sélection « tout le filtre » vidée à chaque changement, confirmation « N sélectionné(s), dont M concerné(s) », nouvelle confirmation après 409, formulaire (valeur inactive conservée, filière déduite), mot de passe affiché une fois hors cache, compétences ; CME : compétences seulement ; Direction : lecture seule ;
- `DemoAccountsSeeder` : fiche de `apprenant.demo@jobdating.test` (CNIE fictive) si un campus, une promotion et une classe actifs existent, sinon ignorée avec un message.

Limites et points à signaler :
- la concurrence sur MySQL est vérifiée par l'**ordre des requêtes de verrouillage** (classe verrouillée avant le compte, par identifiant croissant), pas par un vrai test à deux connexions simultanées ;
- **mesure H2** faite par Codex pendant la revue : désactivation de 2 000 comptes (sessions, liens, journal) en **12,7 s** en local, hors trajet HTTP ;
- le faux de `Notification` ne tient pas compte de `afterCommit` : le test « aucune notification après annulation » utilise la vraie file `database`.

Passages de relais : T-13 (même règle CNIE, même ordre de verrouillage — toutes les classes de l'import par identifiant croissant —, liens annulés si l'e-mail change, e-mails à la validation finale) ; T-14 (inscription des apprenants à l'événement) ; T-20 (état du CV dans la fiche) ; T-23 / T-24 (routes entreprise distinctes, sans CNIE ni téléphone) ; T-40 (compétences actives seulement).

Contrôles (19/11) : Pint et PHPStan sans erreur ; tests T-12 réussis (Docker) : 33 dans `LearnerTest` et 1 pour les données de démonstration ; suite backend complète dans Docker : **406 tests réussis, 2040 assertions** (≈ 4 min 20, code de sortie 0) ; frontend lint, **78 tests** et build réussis ; navigateur (administrateur de démonstration, avant la décision sur la CNIE) : liste et fiche affichées.

Revue du code par Codex (`docs/reviews/REVIEW-T-12-apprenants.md`, « À corriger avant fusion », faite sur la version où la CNIE était encore masquée). Décisions du responsable technique (19/11) : tout corriger.
- constat 1 (requête sans `ids` ni `filters` traitée comme « tous les apprenants ») : refusée (422) pour le comptage et les deux actions, sans écriture ni journal ; `filters: {}` reste la demande explicite de tous les apprenants visibles ; la synthèse indique le mode (« apprenants cochés » ou « filtre ») ;
- constat 2 (ajout de compétence en masse sans verrou des comptes) : comptes verrouillés d'abord, par identifiant croissant, puis fiches ; éligibilité relue sous ces verrous (compte supprimé entre-temps → 409) ; verrou **partagé** sur la compétence ;
- constat 3 (réponse de comptage tardive qui rouvrait la confirmation) : toute réponse arrivée après une annulation, un changement de sélection ou une nouvelle demande est ignorée ;
- constat 4 (recherche en erreur affichée comme une liste vide) : message d'erreur et bouton « Réessayer » ;
- constat 5 (retour à la ligne ou tabulation retirés de la CNIE par `TrimStrings`), option (a) : le champ `cnie` est exclu de `TrimStrings` (`bootstrap/app.php`) ; seuls les espaces sont retirés, tout autre caractère est refusé par l'API (décision 9).

Contrôles après la décision CNIE et les corrections (19/11) : Pint et PHPStan sans erreur ; suite backend complète dans Docker : **410 tests réussis, 2073 assertions** (≈ 3 min 45, code de sortie 0), dont 37 dans `LearnerTest` ; frontend lint, **80 tests** et build réussis.

Contre-revue Codex (`docs/reviews/REVIEW-T-12-contre-revue.md`, « À fusionner après corrections mineures ») : les 5 constats et la décision CNIE sont validés. Deux nouveaux constats mineurs, corrigés (décision du responsable technique du 19/11) :
- constat 6 (ajout individuel de la même compétence pendant un ajout en masse : erreur 500 au lieu de 409) : associations relues en lecture verrouillée avant le recomptage ; test sur la même connexion (le scénario à deux connexions a été rejoué par Codex) ;
- constat 7 (anciennes lignes `learner.cnie_viewed` qui rendaient le journal illisible) : action gardée comme valeur **historique** (« CNIE consultée (ancienne version) »), plus jamais écrite ; test avec une ancienne ligne.

Contrôles finaux (19/11) : Pint et PHPStan sans erreur ; suite backend complète dans Docker : **412 tests réussis, 2084 assertions** (≈ 5 min, code de sortie 0) ; frontend : **80 tests** réussis.

## 2026-11-20 — T-13 (JD-18) : import des apprenants (fichier YouCode School Admin)

Préparation : plan (`docs/tasks/PLAN-T-13.md`) revu par Codex (`docs/reviews/REVIEW-PLAN-T-13.md`, « Plan à revoir », 6 constats majeurs) ; version 2 contre-revue (`docs/reviews/REVIEW-PLAN-T-13-contre-revue.md`, « Plan à revoir », 3 points majeurs et 1 mineur) ; version 2.1 appliquée sans nouvelle contre-revue (décision du 20/11). Format du fichier réel observé sur un export fourni par le responsable technique (données réelles jamais copiées ni utilisées). Décisions du 20/11 dans `DECISIONS.md` (« Import des apprenants (T-13) », classe choisie avant l'import).

Réalisé :
- dépendance **OpenSpout 5.15.3** (accord du responsable technique) ; extension **`pcntl`** ajoutée à l'image Docker PHP (délai des tâches de file) ;
- lecture CSV (virgule ou point-virgule, BOM) et XLSX, en-têtes en ligne 1 ou 2 ; colonnes ignorées jamais copiées ; cellules non texte et formules refusées (« Valeur non admise ») ; limites 1 000 lignes, 5 Mo, 50 Mo décompressés, 50 000 chaînes partagées ; fichiers intermédiaires d'OpenSpout dans un dossier privé supprimé aussitôt (`App\Support\PrivateTemp`) ;
- aperçu (`LearnerImportPlanner`) : campus et promotion choisis, **classe lue sur chaque ligne** (v2.2, ci-dessous) ; motifs de rejet sans CNIE ni e-mail ; à créer, à mettre à jour, inchangés, rejetés ; **empreinte** par ligne ;
- confirmation (fichier relu, 409 si l'aperçu a changé, **clé de confirmation** idempotente, autre empreinte avec la même clé refusée), mise en file **après** la validation, message de file = identifiant seul ;
- traitement (`LearnerImportRunner`, tâche `ProcessLearnerImport` sur la connexion `database-imports`, `retry_after` 900 s, délai 600 s, une tentative) : prise en charge conditionnelle, mots de passe préparés et hachés hors transaction pour tous les nouveaux comptes, verrous classes → comptes → fiches, revalidation de toutes les lignes avant la première écriture, reprise complète (3 fois) après déplacement de classe ou interblocage, finalisation et effacement du `payload` dans la même transaction, erreurs expurgées, **états terminaux protégés** ;
- `LearnerService` : écritures partagées (`insert`, `apply`) et méthodes d'import (`importCreate` avec hachage préparé, compte inactif sans e-mail ; `importUpdate` avec désactivation et réactivation) ; création et modification à la main inchangées ;
- fichier des mots de passe : Excel en clair généré depuis la copie chiffrée, plusieurs téléchargements journalisés, suppression journalisée, valeurs neutralisées ;
- commande `imports:cleanup` toutes les 5 minutes (imports abandonnés, dossiers temporaires de plus de 55 minutes) ;
- permission **`learners.import`** ; journal « Imports et exports » (`learner_import.*`) ;
- frontend : écran « Import des apprenants » (`/apprenants/import`) : choix, aperçu, confirmation, suivi du traitement, rapport, mots de passe, historique ; aperçu effacé à chaque changement, réponse tardive ignorée, même clé renvoyée après une erreur réseau, 409 → nouvel aperçu ;
- fichier de test **fictif** : `C:\Users\Youcode\Desktop\jobdating\fichiers-de-test\import-apprenants-fictif.csv`.

Limites et points à signaler :
- **classe écrite avec un autre alphabet** dans l'export réel (`J Λ I W Λ R`) : la comparaison ignore la casse et les espaces, pas les lettres ; le nom de la classe dans les référentiels doit donc être **écrit comme dans le fichier**, sinon toutes ses lignes sont rejetées ;
- une valeur CSV commençant par `=` est lue comme une formule par OpenSpout et la ligne est rejetée (« Valeur non admise ») ;
- tests de **concurrence à deux connexions MySQL** non écrits dans la suite ; Codex a rejoué **trois scénarios bloquants à deux connexions** (terminés correctement) et la **relivraison à travers le vrai worker** (succès conservé ; échec avant succès → `echec`, `payload` effacé) ; cela ne prouve pas toutes les formes d'interblocage ;
- **mesure de Codex** : 1 000 comptes actifs (bcrypt coût 12) traités en **≈ 302 s** (admission ≈ 2,5 s), pic mémoire PHP 26 Mio, 1 000 notifications mises en file ; la durée reste sous le délai de 600 s ;
- en local, le worker des imports doit tourner **dans Docker** : `docker compose run --rm php php artisan queue:work database-imports --queue=imports --timeout=660` (le PHP Windows n'a pas `pcntl`).

Passages de relais : **import des entreprises** (tâche séparée) ; **T-14** : inscription des apprenants actifs importés à l'événement courant (RG-IMP-07) ; production : worker `database-imports` supervisé sur le serveur Linux avec `pcntl`, planificateur actif (`imports:cleanup`).

Revue du code par Codex (`docs/reviews/REVIEW-T-13-import.md`, « À corriger avant fusion », 4 constats majeurs et 5 mineurs), tous corrigés (accord du 20/11) :
- constat 1 (doublon avec une autre erreur) : les doublons de CNIE et d'e-mail sont cherchés sur **toutes** les lignes ayant une clé admissible, même déjà rejetées pour un autre motif ; toutes les occurrences sont rejetées (le premier motif est conservé) ;
- constat 2 (50 000 chaînes partagées) : comptage réel des éléments `<si>` de `xl/sharedStrings.xml` (lecture en flux, arrêt au-delà de la limite), sans se fier au compteur déclaré ;
- constat 3 (bouton bloqué) : tout changement de choix, « Recommencer » ou l'ouverture d'un rapport libèrent le bouton ; la réponse abandonnée reste ignorée ;
- constat 4 (historique) : bouton « Voir le rapport » par import (état, compteurs, **lignes rejetées**, erreur, mots de passe ; le suivi reprend si l'import est encore en attente ou en cours) ; lignes rejetées affichées dans le rapport ; historique **paginé** (20 par page, « Précédent » / « Suivant ») ;
- constat 5 (point-virgule avec titre) : le séparateur est choisi sur les **deux premières lignes** (celui qui apparaît le plus) ;
- constat 6 (purge) : la mise à jour atomique de `markFailed()` reprend la **même condition d'ancienneté** que la sélection ; un import pris en charge entre les deux n'est plus annulé ;
- constat 7 (lignes inchangées) : elles sont transmises au traitement, **verrouillées et revalidées** avec les autres (aucune écriture) ; une ligne inchangée devenue invalide fait échouer l'import ;
- constat 8 (alerte de suivi) : erreur de suivi séparée des autres, effacée à la première réponse valide ;
- constat 9 (lignes vides) : lecture avec conservation des lignes vides ; numéros de ligne du rapport = numéros réels du fichier.
Tests ajoutés : 5 backend (un par constat 1, 2, 5 + 9, 6 avec prise en charge simulée entre la sélection et l'annulation, 7) et 3 frontend (constats 3, 4, 8).
Changement de règle pendant la contre-revue (plan **v2.2**, décision du 20/11) : l'export réel « Étudiants » filtré par campus et promotion contient **plusieurs classes** ; la classe n'est plus choisie avant l'import :
- la classe de chaque apprenant vient de la colonne `Classe` de sa ligne, comparée sans casse ni espaces aux classes **actives** des référentiels ; motifs « Classe manquante », « Classe inconnue ou désactivée : vérifiez les référentiels », « Classe ambiguë dans les référentiels » ; aucune classe ajoutée depuis l'import ;
- empreinte : classe retenue par ligne ; traitement : verrou sur toutes les classes des lignes (et classes actuelles des apprenants connus), classe désactivée depuis l'aperçu → échec propre ; fichier des mots de passe : classe de chaque apprenant ;
- `learner_imports.class_id` remplacé par `class_counts` (comptes prévus par classe) dans la migration (non encore fusionnée) ; API : plus de `class_id` ; aperçu et rapport : `classes` (comptes par classe) ;
- écran : choix de la classe retiré, tableau « Par classe » dans l'aperçu et le rapport ;
- tests : 4 backend (fichier à 3 classes avec inconnue, désactivée et vide ; classe ambiguë ; apprenant connu changé de classe ; classe désactivée avant la confirmation puis avant le traitement), tests existants adaptés ; tests frontend adaptés (aucun `class_id` envoyé, tableau par classe).
- base de développement : la migration modifiée doit être rejouée (`migrate:rollback --step=1` puis `migrate`, table des imports vide).

Contrôles après correction (20/11) : Pint et PHPStan sans erreur ; suite backend complète dans Docker : **438 tests réussis, 2249 assertions** (≈ 5 min, code de sortie 0), dont 21 pour l'import ; frontend lint, **88 tests** (dont 8 pour l'import) et build réussis. Après le changement v2.2 : Pint et PHPStan sans erreur ; suite backend complète : **442 tests réussis, 2278 assertions** (≈ 6 min, code de sortie 0), dont 25 pour l'import ; frontend lint, **88 tests** et build réussis ; essai manuel par le responsable technique sur l'export réel (3 classes, 25 lignes) : aperçu correct.

Contre-revue du code par Codex (`docs/reviews/REVIEW-T-13-contre-revue.md`, 23/11, « À corriger avant fusion ») : changement v2.2 confirmé, 8 constats sur 9 corrigés et constat 5 partiel ; 1 nouveau constat majeur et 4 mineurs. Décision du 23/11 :
- N1 (réponse tardive d'un rapport, suppression visant le mauvais import) : corrigé ; chaque lecture du rapport, du suivi et de la confirmation est liée à une génération d'affichage, les réponses périmées sont ignorées, l'ancien rapport disparaît pendant le chargement du suivant ;
- N3 (XML de chaînes partagées mal formé → erreur 500) : corrigé ; refus du fichier (422) sans détail XML ;
- N5 (ancienne erreur de suppression restée affichée) : corrigé ;
- N2 (séparateur CSV et valeurs entre guillemets) et N4 (historique sans retour après une page en erreur) : cas rares sans impact, reportés en **E-05** et **E-06** (`ERREURS_A_CORRIGER.md`, T-49).
Tests ajoutés : 1 backend (N3) et 4 frontend (N1 : réponses B puis A avec cible de suppression, ancienne réponse après « Nouvel import », suivi tardif ; N5).
Contrôles (23/11) : Pint et PHPStan sans erreur ; suite backend complète dans Docker : **443 tests réussis, 2281 assertions** (≈ 6 min, code de sortie 0), dont 26 pour l'import ; frontend lint, **92 tests** et build réussis. Essai fonctionnel de l'import jusqu'à la confirmation réalisé par le responsable technique (23/11).

Contrôles (20/11) : Pint et PHPStan sans erreur ; suite backend complète dans Docker : **433 tests réussis, 2230 assertions** (≈ 5 min 30, code de sortie 0), puis deux corrections d'annotations de type demandées par PHPStan (sans effet sur le comportement) et `tests/Feature/Learners` relancé : **55 tests réussis** (dont 16 pour l'import) ; frontend lint, **85 tests** et build réussis.

## 2026-11-23 — T-14 (JD-19) : événements, participants et emplacements

Préparation : plan (`docs/tasks/PLAN-T-14.md`) revu par Codex (`docs/reviews/REVIEW-PLAN-T-14.md`, « Plan à revoir », 3 constats majeurs) ; version 1.3 contre-revue (`docs/reviews/REVIEW-PLAN-T-14-contre-revue.md`, « Plan validé après corrections mineures ») ; version 1.4 avec le résidu R1. Décisions du 23/11 dans `DECISIONS.md` (« Événements et participants (T-14) », « Année des événements et vue entreprise (T-14) »).

Réalisé (non commité, branche `feature/T-14-evenements`) :
- tables `events`, `event_companies`, `event_learners`, `event_locks` ; un seul événement courant (colonne générée et index unique) ; **un seul événement par année scolaire** (index unique, archivés compris) ;
- événements : création, modification, nom terminé automatiquement par l'année scolaire (septembre à août), statut calculé à l'heure du Maroc, désignation avec rapport, retrait du statut, archivage, désarchivage, suppression ;
- inscription automatique des apprenants actifs à la désignation, à la création, à l'import, à la réactivation et à la restauration ; un apprenant **retiré reste retiré** jusqu'à « Réinscrire » ;
- entreprises : événement obligatoire à la création dans une seule transaction (logo nettoyé en cas d'échec), ajout en masse avec rapport, retrait, emplacements par l'administrateur et la CME ;
- fiche de connexion avec événement et emplacement, y compris après réinitialisation ;
- affichage : accueil entreprise (**événement courant uniquement**, sans sélecteur) et « Ma journée » (événement et entreprises présentes, sans contact ni identifiant) ;
- écrans : « Événement et programme » (sans le programme), « Entreprises participantes », « Emplacements des entreprises », état et actions dans « Apprenants » ;
- journal : catégorie « Événements et participants », une seule ligne pour une inscription en masse ;
- aucune nouvelle dépendance ni permission.

Limites et points à signaler :
- `EventUsage` répond **0** jusqu'à T-30 : retraits, suppressions et changements de date ne sont réellement protégés par les passages qu'après T-30 ;
- les tests de concurrence utilisent `pcntl` et `posix` : présents dans l'image Docker, **non déclarés dans la CI** ; à vérifier au premier passage de la CI ;
- le bouton d'ajout s'appelle « Ajouter des entreprises existantes » (accord du responsable technique, 23/11), car il sert à toute entreprise existante ;
- actions non rejouées par Claude dans le navigateur (seulement l'ouverture des pages) ; essais fonctionnels en cours par le responsable technique.

Passages de relais :
- **T-64 (JD-44)** : import des entreprises par fichier, à faire après T-14 ;
- **T-30** : remplacer `NoEventUsage`. Dans une seule transaction : verrou partagé sur la ligne de l'événement, relecture ENSUITE de l'événement (non archivé) et des deux participations (entreprise inscrite ; apprenant non retiré, compte actif), puis création du passage ; tester « retrait contre création de passage » à deux connexions ;
- **T-23, T-30, T-33 et exports** : tous les écrans et exports de l'entreprise se limitent à l'événement courant, côté serveur ; réutiliser `Learner::participatingIn` ;
- **T-16** : écrire les éditions passées directement, avec un rapport ; deux événements sur une même année scolaire bloqueraient la reprise et devront être tranchés.

Contrôles (23/11) : Pint et PHPStan sans erreur ; suite backend complète dans Docker : **515 tests réussis, 2672 assertions** (≈ 7 min 40, code de sortie 0), dont 39 dans `EventTest` et 10 de concurrence à deux connexions MySQL ; frontend lint, **110 tests** et build réussis. Mesure : désignation avec **2 000 apprenants actifs en ≈ 410 ms**, pic mémoire 16 Mio.

Revue du code par Codex (`docs/reviews/REVIEW-T-14-evenements.md`, « À corriger avant fusion », 2 constats majeurs, 3 mineurs et 1 suggestion), tous corrigés (accord du 23/11) :
- constat 1 (données d'un événement enregistrées dans un autre) : anciennes lignes gardées seulement à événement identique, état du panneau remis à zéro à tout changement d'événement, événement dans la clé du formulaire d'emplacement, réponse d'un formulaire fermé ignorée ;
- constat 2 (archive supprimable) : suppression refusée sous verrou, bouton retiré ; il faut d'abord désarchiver ;
- constat 3 (coordonnée isolée) : latitude et longitude contrôlées ensemble sur la valeur obtenue après la modification ;
- constat 4 (journal) : état « avant » capturé avant le changement d'année ; ancien et nouveau nom complet enregistrés ;
- constat 5 (succès tardif) : il ne ferme plus le panneau du nouvel événement ;
- constat 6 (preuve des tests) : blocage réel de l'autre connexion constaté avant de libérer le verrou, attentes bornées, `posix` déclaré ; matrice des droits avec le statut exact de chaque route.
Tests ajoutés : 2 méthodes backend et 4 frontend ; tests d'archivage, de concurrence et matrice renforcés.

Contrôles après correction (23/11) : Pint et PHPStan sans erreur ; suite backend complète dans Docker : **517 tests réussis, 2696 assertions** (durée indiquée 751,68 s, code de sortie 0) ; frontend lint, **114 tests** et build réussis. Le passage a paru bien plus long en temps réel que la durée indiquée ; la cause n'est pas établie.

Contre-revue du code par Codex (`docs/reviews/REVIEW-T-14-contre-revue.md`, 25/11, « À fusionner après corrections mineures ») : constats 1 à 5 corrigés, constat 6 partiel ; reliquat **R1** (le détecteur de blocage acceptait une requête lente ou une autre connexion). Corrigé (accord du 25/11) :
- l'enfant transmet son numéro de connexion MySQL ; seule cette connexion est surveillée ;
- preuve par InnoDB (`performance_schema.data_lock_waits`) : la connexion de l'enfant attend un verrou détenu par celle du parent ;
- deux contrôles négatifs : requête lente sans verrou refusée ; autre connexion réellement bloquée non confondue avec l'enfant ;
- droit de **lecture seule** sur `performance_schema.data_lock_waits` et `performance_schema.threads` accordé à l'utilisateur MySQL local `jobdating` (accord du 25/11) : script `docker/mysql/init/02-lecture-des-verrous.sql` pour un nouveau volume, appliqué une fois en root sur le volume existant. La CI se connecte en root et n'en a pas besoin.

Contrôles (25/11) : Pint et PHPStan sans erreur ; tests de concurrence : **12 réussis** ; suite backend complète dans Docker : **519 tests réussis, 2708 assertions** (durée indiquée 630,70 s, code de sortie 0) ; frontend inchangé depuis le 23/11 (lint, 114 tests et build réussis).

## 2026-11-25 — T-16 abandonnée ; T-64 (JD-44) : import des entreprises par fichier

**T-16 abandonnée** (décision du 25/11) : les entreprises de l'ancienne application ne sont pas reprises ; JD-21 fermée avec un commentaire ; T-63 (passages historiques) reste à trancher.

Préparation de T-64 : plan (`docs/tasks/PLAN-T-64.md`) revu par Codex (`REVIEW-PLAN-T-64.md`, « Plan à revoir ») puis contre-revu (`REVIEW-PLAN-T-64-contre-revue.md`, « Plan à revoir » : longueur de la clé de nom, ordre des verrous, variantes XML) ; version 1.3 avec ces corrections, développement autorisé par le responsable technique sans nouvelle contre-revue. Décisions du 25/11 dans `DECISIONS.md` (« Import des entreprises (T-64) », « Import des entreprises : fiches après l'import (T-64) »).

Réalisé (non commité, branche `feature/T-64-import-entreprises`) :
- **lecteur sélectif dédié** (`CompanyWorkbookReader`, sans OpenSpout) : ne décode que les noms des feuilles, la ligne 1 de « Entreprises Invitées » et les colonnes « Entreprises » et « Confirmation par mail » ; chaînes partagées décodées à la demande (en-têtes d'abord), texte riche et chaînes en ligne ; formules et valeurs non texte refusées ; relations externes et chemins hors de `xl/` refusés ; limites de taille ;
- **clé de nom** `companies.name_key` = SHA-256 du nom simplifié (lettres et chiffres), indexée non unique, remplie pour les entreprises existantes, mise à jour à la création et au renommage ;
- **verrou commun des noms** (`company_name_locks`) pris en premier par l'import, la création et le renommage ; renommage : noms → entreprise → compte ;
- aperçu (à créer, déjà existantes, doublons, rejetées, non confirmées ; noms d'utilisateur réservés dans le lot), confirmation idempotente, traitement en file (`ProcessCompanyImport`, même worker que T-13), comptes et participations dans une seule transaction, nettoyage des imports abandonnés ;
- **fiches** : identifiants chiffrés avec le hachage d'origine ; fiche exclue si mot de passe changé ou réinitialisé, ou compte désactivé ; entreprise retirée ou événement supprimé signalés ; emplacement actuel ; « J'ai imprimé les fiches » sous verrous entreprise → compte ; suppression des mots de passe ;
- écran « Import des entreprises » (`/entreprises/import`) et bouton « Importer (fichier) » ; impression d'une fiche par page ;
- tests : `CompanyImportTest` (24, classeurs fictifs construits par `tests/Support/FakeWorkbook.php`, sentinelles jamais décodées), `CompanyImportConcurrencyTest` (5 à deux connexions MySQL avec preuve d'attente) ; outils de concurrence de T-14 regroupés dans le trait `tests/Concerns/ForksDatabaseConnections.php` ; 4 tests frontend ;
- fichier de test **fictif** : `C:\Users\Youcode\Desktop\jobdating\fichiers-de-test\import-entreprises-fictif.xlsx`.

Limites et points à signaler :
- **mesure** (1 000 entreprises fictives, bcrypt coût 12) : aperçu 0,7 s ; traitement ≈ 278 s, sous le délai de 600 s ; **verrou des noms tenu ≈ 22 s** (une création ou un renommage à la main attend pendant ce temps) ; pic mémoire 22 Mio ;
- la création à la main n'interdit pas les homonymes ; seul l'import les signale ;
- un rehachage automatique du mot de passe à la connexion (non utilisé aujourd'hui) rendrait les fiches de l'import caduques ;
- les classeurs de test fictifs sont construits pièce par pièce ; le classeur réel n'a jamais été utilisé dans les tests.

Contrôles (25/11) : Pint et PHPStan sans erreur ; tests de l'import 24 réussis ; tests de concurrence (T-14 et T-64) 17 réussis ; suite backend complète : 547 réussis et 1 échec (`validite des fiches`), dû au classeur de test réécrit entre l'aperçu et la confirmation (horodatage du zip) ; corrigé en construisant le classeur une seule fois, puis `CompanyImportTest` 24 réussis ; frontend lint, **118 tests** et build réussis.

Revue du code T-64 par Codex (`docs/reviews/REVIEW-T-64-import-entreprises.md`, « À corriger avant fusion », 2 constats majeurs, 2 mineurs, 1 suggestion), tous corrigés (accord du 25/11) :
- constat 1 (mots de passe réaffichés par une réponse tardive) : chaque affichage des fiches a son numéro, invalidé au masquage, à la suppression et au changement d'import ; bouton bloqué pendant le chargement ; fiches affichées seulement si le rapport annonce des identifiants ;
- constat 2 (« Oui ? » accepté) : confirmation comparée sans casse ni espaces seulement (`CompanyImportPlanner::confirmed`), la ponctuation n'est plus retirée ;
- constat 3 (historique « créées » pour un échec) : « à créer », « prévues, aucune créée » ou « créées » selon l'état ;
- constat 4 (422 au lieu de 409) : même clé avec un autre aperçu → 409 `confirmation_key_reused` ;
- constat 5 (ordre des verrous non prouvé) : l'autre connexion est libérée quand le renommage tient son premier verrou ; elle échoue si une de ses transactions est annulée puis rejouée ; **vérifié en inversant temporairement l'ordre dans `CompanyService::update`** : les deux tests échouent, puis code d'origine rétabli.
Tests renforcés : contenu déchiffré du `payload` et des identifiants, comptes et journal après un échec, 7 cas de confirmation, 2 scénarios frontend (masquage et suppression avec réponse tardive) et l'historique.

Incident de mesure : deux suites de tests lancées en même temps sur `jobdating_test` (Codex et Claude) se sont détruit mutuellement leurs tables (28 échecs, non liés au code) ; une suite complète lancée seule ensuite : 548 réussis. **Ne jamais lancer deux suites backend en parallèle.**

Contrôles après correction (26/11, suite lancée seule) : Pint et PHPStan sans erreur ; suite backend complète dans Docker : **555 tests réussis, 2922 assertions** (≈ 11 min 40, code de sortie 0) ; frontend lint, **120 tests** et build réussis.

Contre-revue du code T-64 par Codex (`docs/reviews/REVIEW-T-64-contre-revue.md`, « À corriger avant fusion ») : constats 2 à 5 validés, constat 1 partiel ; deux nouveaux constats corrigés (accord du 26/11) dans `CompanyImportPage.tsx` :
- N1 (majeur, mots de passe réaffichés si le même import est rouvert pendant la suppression) : la suppression en cours est suivie par import et non par vue ; tant qu'elle dure, les fiches de cet import ne peuvent pas être chargées et toute réponse de fiches est ignorée ; à la fin, les fiches sont effacées et l'import affiché est relu, même si la vue a changé ;
- N2 (mineur, double clic sur « Supprimer les mots de passe ») : second déclenchement ignoré, bouton désactivé (« Suppression des mots de passe… »).
Test frontend ajouté (double clic, réouverture pendant la suppression) ; vérifié rouge sans le correctif, puis correctif rétabli.
Contrôles (26/11) : frontend lint, **121 tests** et build réussis ; backend non modifié depuis la suite complète (555 tests).

Vérification Codex de N1 et N2 (`docs/reviews/REVIEW-T-64-verification.md`, « À fusionner après corrections mineures ») : N1 et N2 corrigés ; nouveau constat V1 (mineur) corrigé (accord du 26/11) : après une suppression réussie, l'import affiché est marqué tout de suite « mots de passe supprimés », sans attendre la relecture ; si celle-ci échoue, les actions des fiches ne restent plus proposées. Test ajouté (suppression 204 puis relecture 503), vérifié rouge sans le correctif.
Contrôles (26/11) : frontend lint, **122 tests** et build réussis ; backend non modifié.
