# Prompt de revue Codex — T-04 (JD-11) : connexion

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating (étape 3 du processus : US → développement Claude → **revue** → corrections Claude → validation par le responsable technique). Tu n'es pas l'implémenteur : **tu ne modifies aucun fichier de code, tu ne commites rien, tu ne pousses rien.** Ton seul livrable est le rapport `docs/reviews/REVIEW-T-04-connexion.md`.

Applique `AGENTS.md` (consignes de revue) et `CLAUDE.md` (règles du projet). Réponds en français, simplement.

## 1. Ce qui est à revoir

- Branche locale : `feature/T-04-connexion`, créée depuis `develop` (`4bf7d66`).
- **Le travail n'est pas encore commité** (le responsable technique commite après validation). Le diff à examiner est donc :
  - `git diff develop` (fichiers modifiés) ;
  - **et** les fichiers non suivis, listés par `git status --short` : `backend/app/Http/**`, `backend/database/migrations/2026_11_14_090000_update_users_table_for_authentication.php`, `backend/database/seeders/DemoAccountsSeeder.php`, `backend/tests/Feature/Auth/**`, `frontend/src/**` (nouveaux dossiers `auth`, `lib`, `layout`, `pages`, `test`, `assets`, fichier `AppRoutes.tsx`).
- Hors périmètre de la revue (fichiers locaux, ne pas les commenter) : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `docs/reviews/` (sauf ce prompt), `backend/.env` (non versionné).

## 2. Référence fonctionnelle (à la place de `US-T-04.md`, qui n'existe pas)

Il n'y a pas de fichier de user story pour T-04. Le périmètre validé est dans :

1. `docs/tasks/CURRENT_TASK.md` : périmètre validé et hors périmètre de T-04 ;
2. `docs/reference/CAHIER_DES_CHARGES_V2.md` : **AUTH-01** (RG-AUTH-01 à 05), **3.3** (RG-ACC-01 à 06), **8.2** (table `users`), **9.5** (Sanctum session par cookie + CSRF, pas de jetons), **10.1** (EXI-SEC-01 à 11) ;
3. `docs/DECISIONS.md`, section « Décisions validées après le CDC V2 » (14/11/2026). **Elles priment sur le CDC** : en particulier, le changement de mot de passe à la première connexion de l'apprenant n'est **pas** obligatoire (écart volontaire avec RG-AUTH-13) ; ne le signale pas comme un oubli ;
4. `docs/PROGRESS.md`, entrée « 2026-11-14 — T-04 » : réalisé, hypothèses, points d'attention ;
5. maquette de connexion : `C:\Users\Youcode\Desktop\jobdating\maquettes\00-connexion.png`. Ajout validé par le responsable technique : photo du campus à gauche sur grand écran (≥ 1024 px) uniquement.

Choix validés à ne pas remettre en cause : accueil de la Direction = pilotage en lecture seule ; ajout de TanStack Query, React Hook Form, Zod et `@hookform/resolvers` ; activation, oubli et changement du mot de passe dans **T-05** ; réinitialisation par l'administrateur dans T-10, T-11, T-12 ; journal d'audit dans **T-06**.

## 3. Points à vérifier en priorité

**Sécurité de la connexion**
- Message générique : un identifiant inconnu et un mauvais mot de passe sont-ils indiscernables (code, message, statut) ? Un compte désactivé, non activé ou sans rôle ne révèle-t-il son état qu'après un mot de passe correct ? Y a-t-il une fuite par différence de temps de réponse (`Hash::check` non exécuté si le compte n'existe pas) ? Qualifie le risque, sans exagérer.
- Compte supprimé (suppression logique) : bien traité comme inexistant, à la connexion et pour une session déjà ouverte ?
- Fixation de session : session régénérée à la connexion ; à la déconnexion, session invalidée et jeton CSRF régénéré (RG-AUTH-04).
- RG-AUTH-01 : limiteur `login` dans `AppServiceProvider` (5/min par identifiant + IP, 100/min par IP). Clé contournable (casse, espaces, identifiant absent ou non textuel) ? Réponse 429 avec délai correct ? Comportement derrière un proxy (`$request->ip()`, proxies de confiance) à signaler pour la mise en ligne.
- RG-AUTH-02 : durée de 12 h depuis la connexion (`EnsureSessionIsValid`). Le cas « clé de session absente → le délai démarre maintenant » est-il acceptable ou exploitable ?
- RG-ACC-05 : un compte désactivé perd-il sa session dès la requête suivante sur **toutes** les routes protégées ? Le middleware `session.valid` est-il appliqué partout où il le faut, et sera-t-il facile de ne pas l'oublier sur les futures routes ?
- Sanctum : `statefulApi()`, `SANCTUM_STATEFUL_DOMAINS`, aucun jeton émis ni accepté (la table `personal_access_tokens` existe encore : risque réel ou non ?). Protection CSRF effective hors tests.
- Erreurs JSON (`bootstrap/app.php`) : aucune information technique (EXI-SEC-05), statuts corrects (401, 403, 404, 419, 422, 429), pas de redirection HTML pour l'API.
- `GET /api/v1/me` : aucune donnée sensible (mot de passe, jeton de session, CNIE).

**Données et migration**
- Migration `users` : conforme au CDC 8.2 ; `email` nullable unique (MySQL 8 strict) ; `username` unique ; `down()` réversible ; effet sur une base existante. `email_verified_at` retiré : impact sur Laravel (`MustVerifyEmail` non utilisé) ?
- `UserFactory` : rôle attribué en `afterCreating` (règle issue de la revue T-03) ; aucune donnée personnelle réelle.
- `DemoAccountsSeeder` : impossible à exécuter hors `APP_ENV=local` (RG-AUTH-05, EXI-SEC-11) ; non appelé par `DatabaseSeeder` ; idempotent ; mot de passe de démonstration acceptable seulement en local.

**Frontend**
- Les droits restent appliqués côté serveur ; la garde de route ne fait que guider l'interface (RG-ACC-01).
- Client API (`frontend/src/lib/api.ts`) : cookie `XSRF-TOKEN`, en-tête `X-XSRF-TOKEN`, rejeu **une seule fois** après 419, gestion 401 (retour à la connexion puis à la page demandée, message de session expirée) et 403 (accueil du rôle + message, RG-ACC-03). Redirection ouverte possible via `state.from` ?
- Un utilisateur connecté qui ouvre `/login` est dirigé vers son accueil (RG-AUTH-03).
- Après déconnexion, aucune donnée de l'utilisateur précédent ne reste en cache (TanStack Query).
- Conformité à la maquette `00-connexion` et affichage mobile 375 px ; la photo n'apparaît qu'à partir de 1024 px.
- TypeScript strict, pas de `any` injustifié.

**Tests**
- Les tests couvrent-ils réellement les règles (et pas seulement le cas nominal) ? Risque de faux positif, par exemple : test de déconnexion qui appelle `forgetGuards()`, test du seeder qui change d'environnement, tests de session avec `actingAs`.
- Cas manquants importants (cite-les, sans les écrire).

## 4. Contrôles à exécuter

MySQL Docker démarré (`docker compose up -d`, port 3307). Tests sur `jobdating_test`, jamais SQLite.

```powershell
# backend/
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test

# frontend/
npm.cmd run lint
npm.cmd run test
npm.cmd run build
```

Résultats obtenus par Claude le 14/11/2026, à **confirmer ou infirmer** avec tes propres exécutions : Pint OK, PHPStan sans erreur, 169 tests backend réussis (380 assertions, environ 15 min), lint OK, 10 tests Vitest réussis, compilation OK. Environnement : **PHP 8.5.12** (Herd n'a pas PHP 8.4, ENV-01 ouvert), Node 24. Si tu ne peux pas exécuter un contrôle, dis-le explicitement ; ne prétends jamais l'avoir exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-T-04-connexion.md`

Reprends la structure des revues précédentes (`docs/reviews/PR-3-T-03-roles-permissions.md`) :

1. En-tête : date, objet, branche, commit de base, état (« non commité »), fichiers examinés.
2. Références et périmètre : ce qui a été comparé, périmètre respecté ou non.
3. Constats numérotés, du plus grave au moins grave. Pour chacun :
   - **Sévérité** : Bloquant / Majeur / Mineur / Suggestion ;
   - **Fichier et ligne** ;
   - **Qualification** : bug confirmé (reproduit) ou risque hypothétique ;
   - **Scénario reproductible** (requête, commande ou test) ;
   - **Impact** ;
   - **Correctif minimal proposé**, sans fonctionnalité ni refactoring hors périmètre.
4. Autres points vérifiés sans remarque.
5. Écarts avec le CDC ou les décisions : tout point non tranché par les références est signalé comme **question** au responsable technique, pas comme un bug.
6. Validation et limites : contrôles réellement exécutés et leurs résultats exacts.
7. **Verdict** : « À fusionner », « À fusionner après corrections mineures » ou « À corriger avant fusion ».

Ne valide pas la tâche sans résultats de tests locaux communiqués (AGENTS.md).
