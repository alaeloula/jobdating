# Prompt de revue Codex — T-11 (JD-16) : entreprises

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as revu le **plan** de T-11 (`docs/reviews/REVIEW-PLAN-T-11.md`). Le code est écrit : tu fais la **revue du code**. **Tu ne modifies aucun fichier, tu ne commites rien, tu ne pousses rien, tu ne crées aucun fichier dans le dépôt** (bancs en mémoire ou tables préfixées supprimées ensuite). Seul livrable : `docs/reviews/REVIEW-T-11-entreprises.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à revoir

- Branche `feature/T-11-entreprises`, base `develop` (`bde6a2a`), **non commitée** : `git diff develop` **et** fichiers non suivis.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- **Tests dans Docker (PHP 8.4)** : `docker compose run --rm php php artisan test …`. MySQL sur `jobdating_test`, jamais SQLite.

## 2. Références

1. **`docs/tasks/PLAN-T-11.md`, version 2** : contrat de référence. Décision du responsable technique : **pas de contre-revue du plan** ; vérifie **dans le code** les points marqués **[v2]** issus de ta revue (version des fiches et confirmation d'impression, atomicité du logo, propositions d'identifiant toujours valides, valeurs inactives des profils et du secteur, identité du compte `users`, passage de relais à T-14, points de vigilance).
2. `docs/DECISIONS.md` : 14/11, 16/11, 17/11, 19/11 (dont « RG-ENTR-08 remplace PC-14 » et, dans le plan, la décision 8 : « Fiche imprimée » = **confirmation par l'administrateur**). Elles priment sur le CDC.
3. CDC V2 : ADM-02 (RG-ENTR-01 à 07, critères), ADM-07 (RG-PAR-02), PC-05, RG-ACC-01 à 06, matrice 3.2, modèle 8.2, TRA-01, ENT-08 ; présentation V2 (RG-ENTR-08, recruteurs).
4. Maquettes : `C:\Users\Youcode\Desktop\jobdating\maquettes\admin-03-entreprises-participantes.png`, `admin-04-nouvelle-entreprise.png`, `admin-05-fiche-de-connexion.png`, `entreprise-08-profil.png` (événement, emplacement, « Retirer », import : hors périmètre).
5. `docs/PROGRESS.md` (entrée « 2026-11-19 — T-11 ») ; `docs/ERREURS_A_CORRIGER.md` (E-01 à E-03 : ne pas les signaler).

**Écart signalé par Claude** : le logo est stocké sur le disque **privé** et servi par `GET /api/v1/companies/{id}/logo` (permission `companies.view` ou `companies.manage`), au lieu du disque public prévu par le plan : vérifie que ce choix est sûr et cohérent.

## 3. Points à vérifier en priorité

- **Droits** (`routes/api.php`, `CompanyController`, `MyCompanyController`, `app/Enums/Role.php`, `PermissionMatrixTest`) : chaque route pour les 5 rôles ; CME limitée à la lecture et aux profils ; jamais de `credentials` hors création / réinitialisation ; `PUT /me/company` limité à l'entreprise connectée et à la liste blanche.
- **Création** (`app/Services/Companies/CompanyService.php`) : compte activé, rôle entreprise, `first_name` tronqué, mot de passe `PasswordGenerator`, aucune notification ; collision de nom d'utilisateur au dernier moment ; rollback complet et fichier supprimé en cas d'échec.
- **Nom d'utilisateur** (`UsernameSuggester`) : simplification, doublons (supprimés compris), longueur 3 à 50, suffixes, format.
- **Fiche** : version incrémentée, confirmation liée à la version courante sous verrou, idempotence, 409 pour une ancienne fiche ; `no-store` ; mot de passe hors du journal et hors du cache TanStack (`src/companies/*`).
- **Réinitialisation et désactivation** : `closeAll()` (jamais `afterPasswordChange()`), liens annulés, refus pour un compte désactivé, aucun e-mail.
- **Logo** : contrôle du contenu réel, formats, taille, dimensions, nom aléatoire, stockage privé, en-têtes de la réponse, suppression de l'ancien après validation, remplacements successifs.
- **Profils** : nouvelles valeurs actives seulement, associations inactives conservées, retrait volontaire, doublons, journal avant / après ; bandeau exact de la décision du 16/11.
- **Journal** : catégorie « Entreprises », libellé du sujet, valeurs sans secret.
- **Frontend** : liste, formulaire, fiche imprimable (`index.css`, `@media print`), profils, « Mon profil » entreprise, `apiRequest` avec `FormData`, garde de route et lien d'en-tête.
- **Données de démonstration** (`DemoAccountsSeeder`) : fiche de l'entreprise de démonstration sans événement.
- **Tests** (`tests/Feature/Companies/CompanyTest.php`, `src/companies/companies.test.tsx`) : faux positifs, cas manquants importants.

## 4. Contrôles

**Ne relance pas la suite complète** : Claude vient de l'exécuter. Cite ses résultats comme **communiqués par Claude, non rejoués**. Tu restes libre d'exécuter les contrôles ciblés que tu juges utiles.

Résultats de Claude (19/11/2026) :
- `vendor/bin/pint --test` : réussi ; `vendor/bin/phpstan analyse` : aucune erreur ;
- `tests/Feature/Companies`, `tests/Feature/Authorization`, `tests/Feature/Audit` (Docker, PHP 8.4) : **200 tests réussis** (dont 23 pour T-11) ;
- suite complète dans Docker : **364 tests réussis, 1719 assertions** (≈ 5 min, code de sortie 0) ; `DemoAccountsSeederTest` relancé seul après la dernière modification du seeder : 2 tests réussis ;
- frontend : lint réussi, **56 tests réussis**, build réussi ;
- navigateur (administrateur de démonstration) : création d'une entreprise fictive, identifiant proposé, fiche affichée, impression confirmée.

Environnement : PHP 8.4 dans Docker pour les tests, MySQL 8.0, Node 24. La suite de Claude est **terminée** : `jobdating_test` est libre. Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-T-11-entreprises.md`

En-tête ; références et périmètre ; constats numérotés du plus grave au moins grave (Bloquant / Majeur / Mineur / Suggestion, fichier et ligne, bug confirmé ou risque hypothétique, scénario reproductible, impact, correctif minimal) ; pour chaque constat rare et sans impact sur les tâches suivantes, indique-le (report possible dans `ERREURS_A_CORRIGER.md`) ; vérification des points [v2] ; points vérifiés sans remarque ; questions au responsable technique ; validation et limites ; **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).
