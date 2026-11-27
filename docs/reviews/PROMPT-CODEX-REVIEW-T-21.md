# Prompt de revue Codex — code de T-21 (JD-23) : consultation du CV par lien temporaire

À copier tel quel dans Codex (nouvelle conversation), lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es le **relecteur technique** du projet JobDating. Tu fais la **revue du code** de T-21. **Tu ne modifies aucun fichier de code, tu ne commites rien, tu ne pousses rien.** Seul livrable : `docs/reviews/REVIEW-T-21-consultation-cv.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

**Données personnelles : n'ouvre aucun vrai CV ni aucun fichier du dossier Téléchargements.** PDF fictifs uniquement (`backend/tests/Support/FakePdf.php`, `backend/tests/Fixtures/cv/`).

**Base de test partagée :** avant de lancer un test backend, vérifie avec `docker ps` qu'aucune autre commande `php artisan test` ne tourne. N'en lance jamais deux à la fois.

## 1. Ce qui est à revoir

- Branche `feature/T-21-consultation-cv`, base `develop` (`eab9845`), **non commitée** : `git diff develop` et fichiers non suivis. Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- Référence : `docs/tasks/PLAN-T-21.md` **v1.2** (décisions 1 à 10), `docs/PROGRESS.md` (entrée T-21), CDC CV-03, ENT-03 (RG-CV-01 à 03), APP-03, 7.5, 7.6, 11.6, UX-09, matrice 3.2 ; maquettes `C:\Users\Youcode\Desktop\jobdating\maquettes\apprenant-04-mon-cv.png`, `entreprise-03-fiche-apprenant.png`, `cme-01-cv-apprenants.png`.

Fichiers principaux : `app/Services/Cv/CvAccess.php`, `app/Http/Controllers/CvConsultationController.php`, `CvController::state`, `CvService` (version), migration `2026_11_27_100000_add_version_to_cv_documents.php`, `routes/api.php`, `AuditAction` ; tests `tests/Feature/Cv/CvConsultationTest.php`, `CvVersionMigrationTest.php` ; frontend `src/cv/pdf.ts`, `src/cv/CvViewer.tsx`, `src/pages/MyCvPage.tsx`, `src/pages/CvPage.tsx`, `src/pages/LearnersPage.tsx`, `src/layout/*`, `src/lib/api.ts`, `src/index.css` (couche de texte), `src/cv/viewer.test.tsx`, `package.json` (`pdfjs-dist` 6.5.438).

## 2. Décisions à ne pas remettre en cause

Décisions 1 à 10 du plan v1.2 (dont pdf.js, entreprise en T-24, journal d'audit, 15 minutes, numéro de version, apprenant désactivé lisible). Signale seulement un écart du code avec elles ou une conséquence non traitée.

## 3. Points à vérifier en priorité

- **Droits** : matrice du § 1 du plan, à l'émission et au service ; droits changés entre les deux ; entreprise refusée ; apprenant limité à son CV ; aucune fuite du chemin, de l'empreinte, de l'URL signée dans le journal.
- **Lien signé** : relatif, intégrité vérifiée avant l'expiration, `download` seul hors signature, compte et version, comportement derrière le proxy Vite.
- **Service du fichier** : en-têtes, nom du fichier, erreurs (absent, illisible), journal (une ligne par service, aucune sur échec, échec du journal → pas de PDF), mémoire.
- **Migration de la version** : lignes existantes, unicité, retour arrière.
- **pdf.js** : options effectives de la version 6.5.438 (aucune exécution du contenu du PDF, aucun formulaire), chargement à la demande, worker local, annulation et libération (tâches, documents, surfaces), couche de texte.
- **Frontend : enchaînements d'erreur** — 503 puis Réessayer, lien expiré renouvelé une seule fois, 401, refus, version remplacée ou retirée pendant l'affichage, réponse tardive, erreurs de pdf.js, téléchargement (double clic, nom, libération), plein écran (focus, Échap), « Mon CV » sans téléversement, Direction en lecture seule.
- **Accessibilité** (UX-09) : couche de texte, commandes au clavier, libellés.
- **Tests** : cas importants manquants ; tests qui resteraient verts malgré un défaut.
- Signale aussi tout autre défaut évident, même hors de cette liste.

Indique pour chaque constat s'il est rare et sans impact sur les tâches suivantes (il pourrait alors être reporté dans `docs/ERREURS_A_CORRIGER.md`).

## 4. Contrôles

Tu peux relancer, **seul sur la base de test**, `tests/Feature/Cv` et `src/cv`. Cite les résultats de Claude (`docs/PROGRESS.md`) comme **communiqués, non rejoués**. Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-T-21-consultation-cv.md`

Première ligne : `# Revue du code T-21 — Consultation du CV par lien temporaire`.

En-tête (date, branche, base) ; périmètre ; constats numérotés du plus grave au moins grave (Bloquant / Majeur / Mineur / Suggestion, fichier et ligne, scénario reproductible, correctif minimal, rare ou non) ; points vérifiés sans remarque ; validation et limites ; **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).
