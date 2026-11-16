# Prompt de contre-revue Codex — T-04 (JD-11) : connexion

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as rendu la revue `docs/reviews/REVIEW-T-04-connexion.md` (verdict : « À corriger avant fusion »). Claude a corrigé les constats : tu fais maintenant la **contre-revue**, comme pour T-03 (`docs/reviews/PR-3-T-03-contre-revue-1983b07.md`).

Règles : applique `AGENTS.md` et `CLAUDE.md`. **Tu ne modifies aucun fichier de code, tu ne commites rien, tu ne pousses rien.** Ton seul livrable est `docs/reviews/REVIEW-T-04-contre-revue.md`. Réponds en français, simplement.

## 1. Ce qui est à revoir

- Branche `feature/T-04-connexion`, base `develop` (`4bf7d66`). **Toujours non commité** : le responsable technique commite après validation.
- Examine `git diff develop` **et** les fichiers non suivis (`git status --short`), comme pour la première revue. Mêmes exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env` (non versionné ; il contient aussi `localhost:5174` pour les tests locaux), `docs/reviews/` sauf tes rapports.
- Référence des corrections : `docs/PROGRESS.md`, section « Corrections après la revue indépendante (Codex…, 16/11) ».

## 2. Décisions du responsable technique prises après ta revue (16/11)

À appliquer, ne pas remettre en cause :
- **Base de développement jetable** (aucune donnée réelle à ce stade ; réponse à ta question 1). L'ancien `name` n'est donc pas repris par la migration ; l'hypothèse est documentée dans la migration. Seul le risque « annulation qui laisse la table à moitié modifiée » devait être corrigé (constat 3).
- **Session sans heure de connexion : refusée** en production (réponse à ta question 2). Les tests passent par `Tests\TestCase::actingAs()`, qui renseigne cette heure ; `be()` simule l'absence.
- Questions 3 (proxy) et 4 (fermeture des sessions lors d'une désactivation administrative) : **reportées** à T-01 et T-10/T-12, notées dans `PROGRESS.md`. Ne pas les reclasser en défauts de T-04.
- Les décisions du 14/11 (`docs/DECISIONS.md`) restent valables : première connexion non bloquante, accueil Direction en lecture seule, photo à partir de 1024 px, dépendances frontend autorisées.

## 3. Ce qu'il faut vérifier

**A. Rejouer chacun de tes 7 constats** avec le même scénario que dans ta revue, et dire s'il est corrigé, partiellement corrigé ou non corrigé :

| # | Constat | Correction annoncée | Fichiers |
|---|---|---|---|
| 1 | Limiteur contournable par variante accentuée | Compteur sur l'id du compte s'il existe, sinon identifiant normalisé sans accents ; recherche partagée `User::findForLogin()` | `app/Providers/AppServiceProvider.php`, `app/Models/User.php`, `app/Http/Controllers/Auth/LoginController.php` |
| 2 | Déconnexion échouée présentée comme réussie | État local effacé seulement après 204 ou 401 ; sinon message d'erreur, l'utilisateur reste sur la page | `frontend/src/auth/queries.ts`, `frontend/src/layout/AppLayout.tsx`, `frontend/src/auth/guards.tsx` |
| 3 | Annulation de migration destructive | `down()` refuse avant toute DDL s'il existe des comptes sans e-mail | migration `2026_11_14_090000_…` |
| 4 | Paramètres de la page demandée perdus | Un seul chemin de navigation dans `GuestOnly`, chemins internes uniquement ; pas de « page demandée » après une déconnexion volontaire | `frontend/src/auth/guards.tsx`, `frontend/src/pages/LoginPage.tsx`, `frontend/src/auth/queries.ts` |
| 5 | Identifiant non textuel → 500 | Valeur non textuelle ignorée par le limiteur (compteur commun), validation 422 | `app/Providers/AppServiceProvider.php` |
| 6 | 419 non traduit hors tests | Rendu sur `HttpException` de statut 419 uniquement | `bootstrap/app.php` |
| 7 | Durée révélant l'existence d'un compte | Vérification d'un hash factice de même coût, mis en cache par coût | `app/Http/Controllers/Auth/LoginController.php` |

**B. Vérifier que les corrections n'ont rien cassé ni ouvert de nouveau risque**, en particulier :
- limiteur : la requête SQL supplémentaire par tentative (recherche du compte) est-elle acceptable ? Un identifiant inconnu et un identifiant existant restent-ils indiscernables (statut, message, code, en-têtes) ? Le compteur commun `login:invalid` pour les valeurs non textuelles peut-il gêner des utilisateurs légitimes ?
- hash factice : stockage en cache (pilote `database` hors tests) ; comportement si le cache est vidé ou si `BCRYPT_ROUNDS` change ; aucun secret exposé ;
- session sans heure refusée : aucune session légitime ouverte par `POST /login` ne doit être refusée ; impact de la surcharge de `actingAs()` dans `tests/TestCase.php` sur les futurs tests ;
- frontend : plus d'appel à `queryClient.clear()` ; seules les requêtes hors `auth` sont supprimées : reste-t-il des données de l'utilisateur précédent ? `GuestOnly` refuse-t-il bien tout chemin non interne (`//domaine`, URL absolue) ?
- rendu 419 : les autres `HttpException` (404, 403, 405…) gardent-ils leur rendu ?

**C. Qualité des tests de régression** : chaque nouveau test échouerait-il sur l'ancien code (pas de faux positif) ? Tests concernés : `LoginRateLimitTest` (variantes, non textuel), `LoginTest` (hash factice, avec `Hash::partialMock()`), `SessionTest` (sans heure, cycle complet sans `actingAs`, 419 français avec CSRF actif), `UsersMigrationTest` (annulation refusée, structure intacte), `frontend/src/auth/auth.test.tsx` (déconnexion échouée, déconnexion 401, paramètres conservés, compte suivant après déconnexion).

## 4. Contrôles à exécuter

MySQL Docker démarré (port 3307), tests sur `jobdating_test`, jamais SQLite.

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

Résultats obtenus par Claude le 16/11/2026, à confirmer ou infirmer : Pint OK, PHPStan sans erreur, tests d'authentification 36/36 (311 assertions) ; suite backend complète relancée (résultat communiqué à part par le responsable technique) ; frontend : lint OK, 14 tests Vitest, compilation OK. Vérifié aussi dans le navigateur avec le vrai backend : retour vers `/pilotage?filtre=exemple` après connexion, déconnexion puis `/pilotage` → connexion. Environnement : PHP 8.5.12 (ENV-01 ouvert), MySQL 8.0, Node 24. Si tu ne peux pas exécuter un contrôle, dis-le ; ne prétends jamais l'avoir exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-T-04-contre-revue.md`

1. En-tête : date, branche, base, état (non commité), fichiers examinés.
2. Rejeu des 7 constats : pour chacun, scénario rejoué, résultat observé, statut (corrigé / partiel / non corrigé).
3. Nouveaux constats éventuels, même format que la première revue : sévérité (Bloquant / Majeur / Mineur / Suggestion), fichier et ligne, bug confirmé ou risque hypothétique, scénario reproductible, impact, correctif minimal sans hors périmètre.
4. Qualité des tests de régression.
5. Points vérifiés sans remarque.
6. Validation et limites : contrôles réellement exécutés, résultats exacts.
7. **Verdict** : « À fusionner », « À fusionner après corrections mineures » ou « À corriger avant fusion ».
