# Prompt de contre-revue Codex — plan de T-21 (JD-23) : consultation du CV par lien temporaire

À copier tel quel dans Codex (nouvelle conversation), lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es le **relecteur technique** du projet JobDating. Tu as rendu `docs/reviews/REVIEW-PLAN-T-21.md` (« Plan à revoir »). Claude a corrigé le plan : tu fais une **contre-revue limitée**. **Tu ne modifies aucun fichier, tu ne commites rien, tu ne pousses rien.** Seul livrable : `docs/reviews/REVIEW-PLAN-T-21-contre-revue.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

**Données personnelles : n'ouvre aucun vrai CV ni aucun fichier du dossier Téléchargements.** PDF fictifs uniquement.

## 1. Ce qui est à revoir

- `docs/tasks/PLAN-T-21.md` **version 1.1** et `docs/tasks/CURRENT_TASK.md`, branche `feature/T-21-consultation-cv` (base `develop`, `eab9845`), documents non commités, aucun code écrit.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.

## 2. Décisions à ne pas remettre en cause

Décisions **1 à 10** du plan v1.1, en particulier, prises le 27/11 par le responsable technique : **rendu par pdf.js** (`pdfjs-dist`, dépendance acceptée ; le lecteur PDF du navigateur n'est jamais utilisé), **numéro de version** renouvelé à chaque remplacement, CV d'un apprenant **désactivé** consultable par l'administrateur, la CME et la Direction. Signale seulement une contradiction ou une conséquence non traitée.

## 3. Ce qu'il faut vérifier

**A. Tes constats** : corrigé / partiel / non corrigé.

| # | Correction annoncée | Section du plan |
|---|---|---|
| 1 | Rendu pdf.js dans le site (aperçu et plein écran) sans lecteur du navigateur ni onglet `blob:` ; réponse fichier toujours en `attachment` avec `sandbox` et `nosniff` | décision 8, § 3 « En-têtes », § 4 |
| 2 | `GET /me/cv` : état personnel (source, date) pour « Mon CV » | § 3 « API », § 4 |
| 3 | Colonne `version` (UUID) renouvelée à chaque ajout ou remplacement effectif ; test A → B → A | décision 9, § 3, § 5 |
| 4 | Identité de chargement apprenant + version ; annulation et réponses tardives ignorées ; plein écran dans le site ; téléchargement par lien temporaire révoqué | § 4 « CvViewer » |

**B. Tes précisions** : signature avec `download` seul exclu ; codes d'erreur distincts et traitement hors `apiRequest` (410 renouvelé une seule fois, 401, 403, 404, 503) ; droits changés après l'émission ; journal (une ligne par service réussi, aucune sur échec, limite des copies locales) ; mémoire ; recette navigateurs.

**C. Effets de bord** : options de pdf.js à fixer pour ne jamais exécuter de contenu du PDF ; chargement du worker et à la demande (taille, compatibilité Vite et tests Vitest) ; accessibilité du rendu en images (texte, zoom, lecteur d'écran) ; téléchargement sur iOS ; migration de `version` sur les lignes existantes et unicité.

**D.** Signale aussi tout défaut évident du plan, même hors de cette liste. Indique pour chaque constat s'il est rare et sans impact sur les tâches suivantes (report possible dans `docs/ERREURS_A_CORRIGER.md`).

## 4. Format du rapport `docs/reviews/REVIEW-PLAN-T-21-contre-revue.md`

Première ligne : `# Contre-revue du plan T-21 — Consultation du CV par lien temporaire`.

En-tête ; périmètre ; tableau des constats rejoués ; précisions ; effets de bord ; nouveaux constats éventuels (Bloquant / Majeur / Mineur / Suggestion, section, scénario, correction minimale, rare ou non) ; **verdict** (« Plan validé », « Plan validé après corrections mineures », « Plan à revoir »).
