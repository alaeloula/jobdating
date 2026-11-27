# Prompt de revue Codex — code de T-20 (JD-22) : téléversement, remplacement et retrait du CV

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu fais la **revue du code** de T-20. **Tu ne modifies aucun fichier de code, tu ne commites rien, tu ne pousses rien.** Seul livrable : `docs/reviews/REVIEW-T-20-cv.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

**Données personnelles : n'ouvre AUCUN vrai CV ni aucun fichier du dossier Téléchargements.** PDF fictifs uniquement.

**IMPORTANT — base de test partagée :** avant de lancer un test backend, vérifie qu'aucune autre commande `php artisan test` ne tourne (`docker ps`, commande des conteneurs `php-run`). Deux suites en parallèle sur `jobdating_test` se détruisent mutuellement leurs tables. N'en lance jamais deux à la fois.

## 1. Ce qui est à revoir

- Branche `feature/T-20-cv`, base `develop` (`2f8a9d8`), **non commitée** : `git diff develop` **et** fichiers non suivis.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- Référence : `docs/tasks/PLAN-T-20.md` **v1.2** (décisions 1 à 11), `docs/DECISIONS.md` (26/11 « Retrait d'un CV »), `docs/PROGRESS.md` (entrée T-20), CDC CV-01, CV-04, section 7, 8.7, 11.6, ADM-03, matrice 3.2. Maquette `C:\Users\Youcode\Desktop\jobdating\maquettes\cme-01-cv-apprenants.png`.

Fichiers principaux : `app/Services/Cv/*` (`PdfInspector`, `CvStorage`, `CvService`, `CvRuleException`), `app/Http/Controllers/CvController.php`, `app/Models/CvDocument.php`, `CvHistoryEntry.php`, migration `2026_11_26_100000_create_cv_tables.php`, `LearnerController`, `LearnerFilters`, `LearnerResource`, `routes/api.php`, `bootstrap/app.php` (413), `CleanupImports`, `AuditAction`, `AuditCategory`, `docker/php/Dockerfile` ; tests `tests/Feature/Cv/*`, `tests/Support/FakePdf.php` ; frontend `src/cv/*`, `src/pages/CvPage.tsx`, `src/pages/LearnersPage.tsx`, `src/learners/api.ts`, `src/layout/AppLayout.tsx`, `src/AppRoutes.tsx`.

## 2. Décisions à ne pas remettre en cause

Décisions 1 à 11 du plan v1.2 et ligne du 26/11 de `DECISIONS.md`. Signale seulement un écart du code avec elles ou une conséquence non traitée.

## 3. Points à vérifier en priorité

- **Droits** : chaque route pour les 5 rôles et sans session ; apprenant supprimé (404 CME/Direction, 409 administrateur) ; aucune fuite du chemin, de l'empreinte ou du nom d'origine (API, journal, historique, erreurs).
- **Contrôle du PDF** : contournements de `PdfInspector` (chaînes, commentaires, flux, noms échappés, `stream` sans fin de ligne attendue, chaînes hexadécimales), faux positifs sur des PDF courants, coût sur 5 Mo.
- **Cycle de vie des fichiers** : préparé / adopté / abandonné, une seule tentative, vérification avant adoption, ancien fichier après validation, nettoyage, garde de transaction (`cv.transaction_base`).
- **Concurrence** : ordre compte → fiche → CV face à T-12, T-13 et T-14 ; preuves d'attente des tests à deux connexions.
- **Taille** : `max:5120`, `UPLOAD_ERR_INI_SIZE`, `PostTooLargeException` limité à la route du CV.
- **Recherche** : filtre `cv`, compteur `without_cv` (mêmes filtres et droits), effet sur les sélections en masse de T-12.
- **Frontend : enchaînements d'erreur** — erreur → correction → nouvel essai (même fichier), erreur réseau, 409 (`no_cv`, `retry`, `learner_deleted`), double clic, envoi face à retrait, réponse tardive après changement de page ou de filtre, panneau « CV » de la liste des apprenants, historique en erreur puis « Réessayer ».
- **Tests** : cas importants manquants ; tests qui resteraient verts malgré un défaut.
- Signale aussi tout autre défaut évident, même hors de cette liste.

Indique pour chaque constat s'il est rare et sans impact sur les tâches suivantes (il pourrait alors être reporté dans `docs/ERREURS_A_CORRIGER.md`).

## 4. Contrôles

Tu peux relancer, **seul sur la base de test**, `tests/Feature/Cv` et `src/cv/cv.test.tsx`. Ne relance pas la suite complète : cite les résultats de Claude (`docs/PROGRESS.md`, entrée T-20) comme **communiqués, non rejoués**. Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-T-20-cv.md`

Première ligne : `# Revue du code T-20 — Téléversement, remplacement et retrait du CV`.

En-tête (date, branche, base) ; périmètre ; constats numérotés du plus grave au moins grave (Bloquant / Majeur / Mineur / Suggestion, fichier et ligne, scénario reproductible, correctif minimal, rare ou non) ; points vérifiés sans remarque ; validation et limites ; **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).
