# Prompt de contre-revue Codex — code de T-21 (JD-23) : consultation du CV par lien temporaire

À copier tel quel dans Codex (nouvelle conversation), lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es le **relecteur technique** du projet JobDating. Tu as rendu `docs/reviews/REVIEW-T-21-consultation-cv.md` (« À fusionner après corrections mineures » : 4 mineurs, 1 suggestion). Claude a traité ce rapport : tu fais une **contre-revue limitée**. **Tu ne modifies aucun fichier de code, tu ne commites rien, tu ne pousses rien.** Seul livrable : `docs/reviews/REVIEW-T-21-contre-revue.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

**Données personnelles : n'ouvre aucun vrai CV ni aucun fichier du dossier Téléchargements.** PDF fictifs uniquement.

**Base de test partagée :** avant de lancer un test backend, vérifie avec `docker ps` qu'aucune autre commande `php artisan test` ne tourne. N'en lance jamais deux à la fois.

## 1. Périmètre

- Branche `feature/T-21-consultation-cv`, base `develop` (`eab9845`), non commitée. Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- Référence : `docs/PROGRESS.md`, paragraphe « Revue du code T-21 par Codex… » ; plan `docs/tasks/PLAN-T-21.md` v1.2 (décisions 1 à 10, à ne pas remettre en cause).

## 2. Ce qu'il faut vérifier

**A. Rejouer tes constats** avec les mêmes scénarios : corrigé / partiel / non corrigé.

| # | Correction annoncée | Fichiers et tests |
|---|---|---|
| 1 | Téléchargement annulé et ignoré après fermeture ou remplacement de la visionneuse | `src/cv/CvViewer.tsx` (`alive`, `downloadAbort`), `src/cv/pdf.ts` (`signal`) ; test « constat 1 : téléchargement abandonné… » |
| 2 | Plein écran dans `<body>`, arrière-plan inerte, boucle Tab / Maj+Tab, Échap global | `CvViewer.tsx` (`createPortal`, `inert`, `trapTab`) ; test « constat 2 » |
| 3 | Erreurs de document (`InvalidPDFException`, `PasswordException`…) → « Téléchargez-le » ; autres (worker) → Réessayer | `CvViewer.tsx` (`DOCUMENT_ERRORS`) ; test « constat 3 » |
| 4 | `no_cv` et `cv_outdated` relisent l'état une fois (aperçu et téléchargement) | `CvViewer.tsx` (`STALE`), `src/pages/MyCvPage.tsx` ; test « constat 4 » |
| 5 | Tests complétés (frontend et backend, migration) | `src/cv/viewer.test.tsx`, `tests/Feature/Cv/CvConsultationTest.php`, `CvVersionMigrationTest.php` |

**B. Effets de bord** : `inert` posé sur les autres enfants de `<body>` (autres portails, notifications, restauration si la visionneuse est démontée en plein écran) ; liste des erreurs « document » de pdf.js 6.5.438 (noms réels) ; relecture unique sans boucle ; annulation d'une requête de lien déjà partie ; isolation du disque `local` dans `tests/TestCase.php`.

**C.** Signale aussi tout défaut évident dans les fichiers modifiés, même hors de cette liste. Indique pour chaque constat s'il est rare et sans impact sur les tâches suivantes (report possible dans `docs/ERREURS_A_CORRIGER.md`).

## 3. Contrôles

Tu peux relancer, **seul sur la base de test**, `tests/Feature/Cv` et `src/cv`. Cite les résultats de Claude (`docs/PROGRESS.md`) comme **communiqués, non rejoués**. Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 4. Format du rapport

Première ligne : `# Contre-revue du code T-21 — Consultation du CV par lien temporaire`. Puis : en-tête (date, branche), tableau des constats rejoués, effets de bord, nouveaux constats éventuels (gravité, fichier et ligne, scénario, correctif minimal, rare ou non), validation et limites, **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).
