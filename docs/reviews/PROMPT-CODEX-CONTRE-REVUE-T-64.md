# Prompt de contre-revue Codex — T-64 (JD-44) : import des entreprises par fichier

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as rendu `docs/reviews/REVIEW-T-64-import-entreprises.md` (« À corriger avant fusion » : 2 majeurs, 2 mineurs, 1 suggestion). Claude a traité ce rapport : tu fais une **contre-revue limitée**. **Tu ne modifies aucun fichier de code, tu ne commites rien, tu ne pousses rien, tu ne crées aucun fichier dans le dépôt.** Seul livrable : `docs/reviews/REVIEW-T-64-contre-revue.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

**Données personnelles : n'ouvre AUCUN fichier réel**, en particulier aucun classeur ni document du dossier Téléchargements. Classeurs fictifs uniquement.

**IMPORTANT — base de test partagée :** avant de lancer un test backend, vérifie qu'aucune autre commande `php artisan test` ne tourne (`docker ps`, commande des conteneurs `php-run`). Deux suites en parallèle sur `jobdating_test` se détruisent mutuellement leurs tables (incident du 25/11). N'en lance jamais deux à la fois.

## 1. Ce qui est à revoir

- Branche `feature/T-64-import-entreprises`, base `develop` (`c3f0365`), **toujours non commitée** : `git diff develop` **et** fichiers non suivis.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- Référence des corrections : `docs/PROGRESS.md`, paragraphe « Revue du code T-64 par Codex… ».
- **Périmètre** : les 5 constats, leurs effets de bord, et les compléments de tests que tu avais proposés.

## 2. Décisions à ne pas remettre en cause

Décisions 1 à 10 du plan T-64 v1.3 et lignes du 25/11/2026 de `docs/DECISIONS.md`. La confirmation se compare « sans casse ni espaces » (décision 2) : la ponctuation compte.

## 3. Ce qu'il faut vérifier

**A. Rejouer tes constats** avec les mêmes scénarios : corrigé / partiel / non corrigé.

| # | Correction annoncée | Fichiers et tests |
|---|---|---|
| 1 | Numéro d'affichage des fiches (`sheetsRequest`), invalidé par `hideSheets` au masquage, à la suppression et au changement d'import ; bouton bloqué pendant le chargement ; fiches rendues seulement si `has_credentials` | `src/pages/CompanyImportPage.tsx` ; test « une réponse tardive ne réaffiche jamais les fiches… » |
| 2 | `CompanyImportPlanner::confirmed` : espaces (et espace insécable) retirés, casse ignorée, ponctuation conservée | `test_confirmation_sans_casse_ni_espaces_seulement` (7 cas) |
| 3 | Libellés « à créer », « prévues, aucune créée », « créées » | test « historique : « créées » seulement pour un import terminé » |
| 4 | `ConfirmationKeyReusedException` : 409 `confirmation_key_reused` | `test_confirmation_idempotente_et_apercu_change` |
| 5 | `releaseBetweenRenameLocks` (libération entre le premier et le second verrou du renommage) et `withoutRollback` (échec si une transaction de l'enfant est annulée puis rejouée) ; vérification par inversion temporaire de l'ordre (deux tests rouges, code rétabli) | `tests/Feature/Companies/CompanyImportConcurrencyTest.php` |

**B. Effets de bord** : réponses tardives restantes (rapport, impression, suppression suivie d'une relecture) ; double clic sur « Supprimer les mots de passe » ; détection des verrous par expression régulière sur le texte SQL (fragilité, faux positifs) ; écoute de `TransactionRolledBack` dans l'enfant (transactions imbriquées, savepoints) ; compléments de tests (payload et identifiants déchiffrés, comptes et journal après échec).

**C. Signale aussi tout défaut évident** que tu remarques dans les fichiers modifiés, même hors de cette liste et même s'il n'a pas été signalé dans ta première revue.

Indique pour chaque constat s'il est rare et sans impact sur les tâches suivantes (il pourrait alors être reporté dans `docs/ERREURS_A_CORRIGER.md`).

## 4. Contrôles

**Ne relance pas la suite complète** : cite ces résultats comme **communiqués par Claude, non rejoués**. Tu peux relancer, **seul sur la base de test**, `tests/Feature/Companies/CompanyImportTest.php`, `tests/Feature/Companies/CompanyImportConcurrencyTest.php` et `src/companies/companyImport.test.tsx`.

Résultats de Claude (26/11/2026, suite lancée seule) : Pint et PHPStan sans erreur ; suite backend complète : **555 tests réussis, 2922 assertions** (≈ 11 min 40, code de sortie 0) ; frontend lint, **120 tests** et build réussis ; vérification par inversion de l'ordre des verrous : deux tests rouges, code d'origine rétabli. Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-T-64-contre-revue.md`

Première ligne : `# Contre-revue du code T-64 — Import des entreprises par fichier`.

En-tête (date, branche, base) ; périmètre ; tableau des constats rejoués ; effets de bord ; nouveaux constats éventuels (Bloquant / Majeur / Mineur / Suggestion, fichier et ligne, scénario, correctif minimal, rare ou non) ; validation et limites ; **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).
