# Prompt de contre-revue Codex — plan de T-20 (JD-22) : téléversement, remplacement et retrait du CV

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as rendu `docs/reviews/REVIEW-PLAN-T-20.md` (« Plan à revoir » : 3 majeurs, 1 suggestion). Claude a corrigé le plan : tu fais une **contre-revue limitée**. **Tu ne modifies aucun fichier, tu ne commites rien, tu ne pousses rien.** Seul livrable : `docs/reviews/REVIEW-PLAN-T-20-contre-revue.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

**Données personnelles : n'ouvre AUCUN vrai CV ni aucun fichier du dossier Téléchargements.** PDF fictifs uniquement.

## 1. Ce qui est à revoir

- `docs/tasks/PLAN-T-20.md` **version 1.1**, branche `feature/T-20-cv` (base `develop`, `2f8a9d8`), documents non commités, aucun code écrit.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.

## 2. Décisions à ne pas remettre en cause

Décisions **1 à 11** du plan v1.1 (5 à 8 confirmées, 9 à 11 prises le 26/11 par le responsable technique : contrôle PDF simple sans dépendance avec refus des PDF actifs, 404 pour la CME et la Direction et 409 pour l'administrateur sur un apprenant supprimé, `cv_history` conservé comme donnée métier) et ligne du 26/11 de `docs/DECISIONS.md`. Signale seulement une contradiction ou une conséquence non traitée.

## 3. Ce qu'il faut vérifier

**A. Tes constats** : corrigé / partiel / non corrigé.

| # | Correction annoncée | Section du plan |
|---|---|---|
| 1 | Ordre **compte → fiche → CV**, état supprimé relu sous le verrou du compte, résultats selon l'ordre de validation, essais à deux connexions face à la suppression et à la restauration | § 5 « Ordre des verrous », § 8 |
| 2 | États préparé / adopté / abandonné ; écriture et suppression vérifiées ; ancien fichier supprimé après validation sans jamais toucher au nouveau ; appel dans une transaction ouverte refusé (contrat T-22) ; tests d'échec | § 5 « Cycle de vie », § 8 |
| 3 | Reconnaissance, structure (`PdfInspector`) et refus des contenus actifs, limite assumée (flux compressés), en-têtes d'isolement prévus pour T-21 ; faux PDF de 67 octets et PDF tronqué testés | § 4, § 8 |
| 4 | Message du CV limité à `POST /learners/{id}/cv`, autres routes inchangées, tests HTTP réels | § 4 « Dépassement », § 8 |

**B. Effets de bord** : faux positifs du contrôle structurel sur de vrais PDF courants (PDF 1.5+ à flux de références, PDF linéarisés, exportés par Word, LibreOffice, Canva ou navigateur) ; refus de `/EmbeddedFile` ou `/XFA` trop strict pour des CV réels ; test sur le chemin de la requête pour le 413 ; `afterCommit` et `afterRollBack` sans transaction englobante ; nettoyage d'un fichier « préparé » d'un envoi lent de plus d'une heure.

**C. Signale aussi tout défaut évident** du plan, même hors de cette liste et même s'il n'a pas été signalé dans ta première revue.

Indique pour chaque constat s'il est rare et sans impact sur les tâches suivantes (il pourrait alors être reporté dans `docs/ERREURS_A_CORRIGER.md`).

## 4. Format du rapport `docs/reviews/REVIEW-PLAN-T-20-contre-revue.md`

Première ligne : `# Contre-revue du plan T-20 — Téléversement, remplacement et retrait du CV`.

En-tête ; périmètre ; tableau des constats rejoués ; effets de bord ; nouveaux constats éventuels (Bloquant / Majeur / Mineur / Suggestion, section, scénario, correction minimale, rare ou non) ; **verdict** (« Plan validé », « Plan validé après corrections mineures », « Plan à revoir »).
