# Prompt de contre-revue Codex — T-12 (JD-17) : apprenants

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as rendu `docs/reviews/REVIEW-T-12-apprenants.md` (« À corriger avant fusion », 2 constats majeurs, 3 mineurs). Claude a traité ce rapport : tu fais une **contre-revue limitée**. **Tu ne modifies aucun fichier de code, tu ne commites rien, tu ne pousses rien, tu ne crées aucun fichier dans le dépôt** (bancs en mémoire ou tables préfixées supprimées ensuite). Seul livrable : `docs/reviews/REVIEW-T-12-contre-revue.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à revoir

- Branche `feature/T-12-apprenants`, base `develop` (`d6cb56f`), **toujours non commitée** : `git diff develop` **et** fichiers non suivis.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- Référence des corrections : `docs/PROGRESS.md`, entrée « 2026-11-19 — T-12 », paragraphe « Revue du code par Codex… ».
- **Périmètre** : les 5 constats, la **nouvelle décision sur la CNIE** (partie 3.B) et leurs effets de bord. Le reste de T-12 a déjà été revu.
- **Tests dans Docker (PHP 8.4)** : `docker compose run --rm php php artisan test …`. MySQL sur `jobdating_test`, jamais SQLite.

## 2. Décisions du responsable technique (19/11), à ne pas remettre en cause

- Les 5 constats sont corrigés ; constat 5 : option (a), le champ `cnie` est exclu de `TrimStrings`.
- **Nouvelle décision prise pendant ta revue** (`DECISIONS.md`, « CNIE visible par l'administrateur, la CME et la Direction ») : la CNIE est affichée **en entier** dans la liste et la fiche pour ces trois rôles, recherche par CNIE comprise ; **plus de journalisation de chaque consultation** ; elle n'est **jamais** visible par les entreprises et reste masquée dans le journal d'audit. Le téléphone reste réservé à l'administrateur. Elle remplace la décision 3 du plan et contredit volontairement RG-APR-06 : ne la remets pas en cause, mais vérifie son implémentation.

## 3. Ce qu'il faut vérifier

**A. Rejouer tes constats** avec les mêmes scénarios : corrigé / partiel / non corrigé.

| # | Correction annoncée | Fichiers et tests |
|---|---|---|
| 1 | Requête sans `ids` ni `filters` refusée (422) dans `LearnerController::selection()`, pour le comptage et les deux actions ; `filters: {}` accepté ; synthèse avec `mode` | `LearnerController.php` ; `LearnerTest::test_requete_sans_selection_refusee_sans_effet` |
| 2 | `bulkAddCompetence` : comptes verrouillés (id croissant) puis fiches, éligibilité relue sous les verrous, 409 si le nombre change ; verrou **partagé** sur la compétence | `LearnerService.php` ; `LearnerTest::test_ajout_de_competence_compte_supprime_entre_temps` (suppression simulée entre la lecture des candidats et le verrou des comptes, sur la même connexion) |
| 3 | Numéro de demande (`bulkRequest`) : réponse de comptage ignorée après annulation, changement de sélection, de filtre ou de page, ou nouvelle demande | `LearnersPage.tsx` ; test « comptage arrivé après Annuler » |
| 4 | État d'erreur de la recherche : message et « Réessayer » | `LearnersPage.tsx` ; test « recherche en erreur » |
| 5 | `trimStrings(except: ['cnie'])` ; retour à la ligne et tabulation (finaux ou au milieu) refusés par l'API | `bootstrap/app.php` ; `LearnerTest::test_cnie_invalide_refusee_sans_repeter_la_valeur` |

**B. Nouvelle décision CNIE** (`LearnerResource`, `LearnerController::show`, `LearnerFilters`, `Role.php`, `PermissionMatrixTest`, `AuditAction`, `src/pages/LearnersPage.tsx`, `src/learners/*`) :
- CNIE complète pour les comptes ayant `learners.view_cnie` (administrateur, CME, Direction), dans la liste, la fiche et la recherche ; téléphone et identifiant CME toujours réservés à l'administrateur ;
- entreprise : aucune route T-12 (403), aucune CNIE dans aucune réponse ; vérifie que rien dans T-12 ne prépare une fuite pour T-23 / T-24 ;
- action `learner.cnie_viewed` supprimée : aucune trace restante (catégorie « CNIE » toujours présente dans le filtre du journal, pour les tâches suivantes) ;
- CNIE toujours masquée dans le journal d'audit, absente des messages d'erreur, des collisions SQL et des synthèses en masse.

**C. Effets de bord** : ordre des verrous de `bulkAddCompetence` face à `updateCompetences` (compte puis fiche) et à la désactivation d'une compétence dans les référentiels ; exclusion de `cnie` de `TrimStrings` pour les autres routes ; qualité des nouveaux tests (échoueraient-ils sur l'ancien code ?).

**D. Signale aussi tout défaut évident** que tu remarques dans les fichiers modifiés, même hors de cette liste.

## 4. Contrôles

**Ne relance pas la suite complète** : Claude vient de l'exécuter. Cite ses résultats comme **communiqués par Claude, non rejoués**.

Résultats de Claude (19/11/2026) :
- Pint et PHPStan sans erreur ; suite backend complète dans Docker : **410 tests réussis, 2073 assertions** (≈ 3 min 45, code de sortie 0), dont 37 dans `LearnerTest` ; frontend lint, **80 tests** et build réussis ;
- les 2 nouveaux tests frontend échouent sans les corrections (vérifié en retirant temporairement les corrections).

Mesure H2 : la tienne (12,7 s pour 2 000 comptes) est reprise dans `PROGRESS.md`. Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-T-12-contre-revue.md`

En-tête ; périmètre ; tableau des constats rejoués ; vérification de la décision CNIE ; effets de bord ; nouveaux constats éventuels (Bloquant / Majeur / Mineur / Suggestion, fichier et ligne, scénario, correctif minimal ; indique si un constat est rare et sans impact sur les tâches suivantes) ; validation et limites ; **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).
