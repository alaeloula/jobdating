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
