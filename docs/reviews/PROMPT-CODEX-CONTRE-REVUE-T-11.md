# Prompt de contre-revue Codex — T-11 (JD-16) : entreprises

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as rendu `docs/reviews/REVIEW-T-11-entreprises.md` (3 constats). Claude a traité ce rapport : tu fais une **contre-revue limitée**. **Tu ne modifies aucun fichier de code, tu ne commites rien, tu ne pousses rien, tu ne crées aucun fichier dans le dépôt** (bancs de contrôle en mémoire ou tables préfixées supprimées ensuite). Seul livrable : `docs/reviews/REVIEW-T-11-contre-revue.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à revoir

- Branche `feature/T-11-entreprises`, base `develop` (`bde6a2a`), **toujours non commitée** : `git diff develop` **et** fichiers non suivis.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- Référence des corrections : `docs/PROGRESS.md`, entrée « 2026-11-19 — T-11 », paragraphe « Revue du code par Codex… ».
- **Périmètre** : uniquement les points ci-dessous et leurs effets de bord. Le reste de T-11 a déjà été revu.
- **Tests dans Docker (PHP 8.4)** : `docker compose run --rm php php artisan test …`. MySQL sur `jobdating_test`, jamais SQLite.

## 2. Décisions du responsable technique (19/11)

À appliquer, ne pas remettre en cause :
- constats **1 et 2** : corrigés ;
- constat **3**, option **(a)** : les profils recherchés **ne se saisissent pas à la création** ; envoyés à `POST /companies`, ils sont **refusés (422)** au lieu d'être ignorés. Ils se saisissent ensuite par « Profils recherchés » (`PUT /companies/{id}/profiles`). Le plan (`docs/tasks/PLAN-T-11.md`) est mis à jour en ce sens ;
- aucun constat n'a été reporté dans `docs/ERREURS_A_CORRIGER.md` (E-01 à E-03 : ne pas les signaler).

## 3. Ce qu'il faut vérifier

**A. Rejouer tes constats** avec les mêmes scénarios, et dire : corrigé / partiel / non corrigé.

| # | Correction annoncée | Fichiers |
|---|---|---|
| 1 | Suppression des fichiers de logo liée au **commit final** : l'ancien logo (remplacement, suppression) est supprimé par `DB::afterCommit` ; le nouveau fichier est supprimé par `DB::afterRollBack` enregistré **avant** la transaction interne quand une transaction englobante est ouverte (`DB::transactionLevel() > 0`), car un rappel enregistré dans la transaction interne est perdu dès qu'elle est validée ; le `catch` local supprime toujours le nouveau fichier si la transaction interne échoue | `backend/app/Services/Companies/CompanyService.php` (`create`, `replaceLogo`, `removeLogo`, `discardOnCommit`, `discardIfEnclosingRollsBack`) ; test `CompanyTest::test_logo_et_transaction_englobante_annulee` |
| 2 | La fiche de connexion est remontée pour chaque version (`key` = identifiant de l'entreprise + version) : l'état « impression confirmée » ne passe plus d'une fiche à la suivante | `frontend/src/pages/CompaniesPage.tsx` ; test « deux réinitialisations sans fermer la fiche » de `frontend/src/companies/companies.test.tsx` |
| 3 | `filiere_ids` et `competence_ids` → règle `missing` à la création (refusés même vides), messages en français | `backend/app/Http/Controllers/CompanyController.php` (`store`, `messages`) ; test `CompanyTest::test_profils_refuses_a_la_creation` ; `docs/tasks/PLAN-T-11.md` |

**B. Effets de bord** :
- constat 1 : sans transaction englobante (appel normal depuis le contrôleur), le comportement est-il inchangé (ancien logo supprimé après validation, nouveau supprimé si échec) ? Transaction englobante **validée** : ancien supprimé une seule fois, nouveau conservé ? Remplacements successifs dans la même transaction englobante ? Aucune suppression en double ni fichier orphelin ? Ce point compte pour **T-13** (import des entreprises, qui appellera `CompanyService` dans une transaction) ;
- constat 2 : aucune régression de la confirmation d'impression (409 pour une ancienne fiche côté serveur inchangé) ; le mot de passe toujours absent du cache TanStack ;
- constat 3 : le formulaire de création (`frontend/src/companies/CompanyForm.tsx`) n'envoie aucun profil ; aucune autre voie de création (seeder de démonstration) n'est cassée.

**C. Changements d'interface demandés par le responsable technique après sa recette** (hors constats, à vérifier rapidement) :
- liste des entreprises (`CompaniesPage.tsx`, `CompanyAvatar`) : logo **rond de 48 px** (`object-contain`), ou initiales si aucun logo ou si l'image ne charge pas ;
- profils recherchés visibles dans la liste (`ProfileBadges`) : filières et compétences en badges, « Aucun profil recherché » sinon ; libellés seulement, aucune donnée sensible ;
- accessibilité minimale (texte alternatif, contraste) ; test « les profils recherchés sont visibles dans la liste » de `companies.test.tsx`.

**D. Qualité des nouveaux tests** : échoueraient-ils sur l'ancien code ?

## 4. Contrôles

**Ne relance pas la suite complète** : Claude vient de l'exécuter. Cite ses résultats comme **communiqués par Claude, non rejoués**. Tu restes libre d'exécuter les contrôles ciblés que tu juges utiles (par exemple `tests/Feature/Companies`).

Résultats de Claude (19/11/2026) :
- `vendor/bin/pint --test` : réussi ; `vendor/bin/phpstan analyse` : aucune erreur ;
- suite complète dans Docker : 366 tests : 365 réussis, 1 échec (`test_profils_refuses_a_la_creation` : la règle `prohibited` accepte une liste vide) ; correction par la règle `missing` (champ refusé dès qu'il est envoyé, même vide), puis `tests/Feature/Companies` relancé : **25 tests réussis** (suite complète non relancée après ce changement limité à la validation de `store`) ;
- frontend : lint réussi, **58 tests réussis**, build réussi ;
- navigateur (administrateur de démonstration) : liste « Entreprises » avec logo rond, initiales et badges de profils.

Environnement : PHP 8.4 dans Docker pour les tests, MySQL 8.0, Node 24. La suite de Claude est **terminée** : `jobdating_test` est libre. Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-T-11-contre-revue.md`

En-tête ; périmètre ; tableau des constats rejoués (corrigé / partiel / non corrigé, avec preuve) ; effets de bord ; changements d'interface ; qualité des tests ; **nouveaux constats** éventuels (Bloquant / Majeur / Mineur / Suggestion, fichier et ligne, scénario, correctif minimal ; indique si un constat est rare et sans impact sur les tâches suivantes) ; validation et limites ; **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).
