# Prompt de contre-revue Codex — code de T-20 (JD-22) : téléversement, remplacement et retrait du CV

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as rendu `docs/reviews/REVIEW-T-20-cv.md` (« À corriger avant fusion » : 2 majeurs, 2 mineurs). Claude a traité ce rapport : tu fais une **contre-revue limitée**. **Tu ne modifies aucun fichier de code, tu ne commites rien, tu ne pousses rien.** Seul livrable : `docs/reviews/REVIEW-T-20-contre-revue.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

**Données personnelles : n'ouvre AUCUN vrai CV ni aucun fichier du dossier Téléchargements.** PDF fictifs uniquement.

**IMPORTANT — base de test partagée :** avant de lancer un test backend, vérifie qu'aucune autre commande `php artisan test` ne tourne (`docker ps`). N'en lance jamais deux à la fois.

## 1. Ce qui est à revoir

- Branche `feature/T-20-cv`, base `develop` (`2f8a9d8`), **toujours non commitée** : `git diff develop` et fichiers non suivis. Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- Référence des corrections : `docs/PROGRESS.md`, paragraphe « Revue du code T-20 par Codex… ».
- **Périmètre** : les 4 constats, leurs effets de bord et les limites de tests que tu avais signalées.

## 2. Décisions à ne pas remettre en cause

Décisions 1 à 11 du plan T-20 v1.2 et ligne du 26/11 de `docs/DECISIONS.md` (contrôle PDF simple sans dépendance, limite assumée sur les flux compressés).

## 3. Ce qu'il faut vérifier

**A. Rejouer tes constats** avec les mêmes scénarios : corrigé / partiel / non corrigé.

| # | Correction annoncée | Fichiers et tests |
|---|---|---|
| 1 | Flux sautés selon `/Length` (directe ou indirecte en clair), `endstream` exigé ; flux non délimitable, chaîne jamais fermée → refus | `app/Services/Cv/PdfInspector.php` ; `test_motifs_des_flux_et_chaines`, cas « image piégée », « longueur de flux fausse », « chaîne jamais fermée », « longueur indirecte » |
| 2 | Verrou nommé MySQL par fichier, pris avant l'écriture et libéré après validation ou abandon ; nettoyage sous ce verrou (sans attente) avec relecture de la référence | `CvStorage::store`, `release`, `sweepOrphans` ; `CvService::upload` ; `CvConcurrencyTest::test_nettoyage_ignore_une_preparation_active` |
| 3 | Panneau « CV » fermé au changement de recherche, de filtre ou de page | `src/pages/LearnersPage.tsx` ; test « Apprenants : le panneau CV se ferme… » |
| 4 | `learner_deleted` → 404 pour CME et Direction, 409 pour l'administrateur | `CvController::refusal` ; `test_suppression_pendant_l_envoi_selon_le_role` |

**B. Effets de bord** : faux positifs du nouveau contrôle sur des PDF courants (longueur indirecte dans un flux d'objets, `/Length` d'un sous-dictionnaire, fin de ligne `\r` seule, PDF à mises à jour incrémentales) ; coût sur 5 Mo ; verrou nommé (réentrance d'une même connexion, libération si exception, connexion rouverte, autres bases que MySQL) ; nettoyage sur un grand nombre de fichiers ; panneau fermé alors que l'envoi continue (message et état ensuite).

**C. Signale aussi tout défaut évident** dans les fichiers modifiés, même hors de cette liste et même s'il n'a pas été signalé dans ta première revue.

Indique pour chaque constat s'il est rare et sans impact sur les tâches suivantes (il pourrait alors être reporté dans `docs/ERREURS_A_CORRIGER.md`).

## 4. Contrôles

Tu peux relancer, **seul sur la base de test**, `tests/Feature/Cv` et `src/cv/cv.test.tsx`. Cite les résultats de Claude (`docs/PROGRESS.md`) comme **communiqués, non rejoués**. Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-T-20-contre-revue.md`

Première ligne : `# Contre-revue du code T-20 — Téléversement, remplacement et retrait du CV`.

En-tête (date, branche, base) ; périmètre ; tableau des constats rejoués ; effets de bord ; nouveaux constats éventuels (Bloquant / Majeur / Mineur / Suggestion, fichier et ligne, scénario, correctif minimal, rare ou non) ; validation et limites ; **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).
