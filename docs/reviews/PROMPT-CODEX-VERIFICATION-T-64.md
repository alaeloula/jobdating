# Prompt de vérification Codex — T-64 (JD-44) : constats N1 et N2 de la contre-revue

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Ta contre-revue `docs/reviews/REVIEW-T-64-contre-revue.md` a relevé N1 (majeur) et N2 (mineur) dans `frontend/src/pages/CompanyImportPage.tsx`. Claude les a corrigés : tu fais une **vérification limitée**. **Tu ne modifies aucun fichier de code, tu ne commites rien, tu ne pousses rien.** Seul livrable : `docs/reviews/REVIEW-T-64-verification.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

**Données personnelles : n'ouvre AUCUN fichier réel**, en particulier rien du dossier Téléchargements.

## 1. Périmètre

- Branche `feature/T-64-import-entreprises`, toujours non commitée : `git diff develop` et fichiers non suivis.
- Fichiers concernés : `frontend/src/pages/CompanyImportPage.tsx` (`deletingIds`, `deleting`, `currentIdRef`, `showSheets`, `remove`, boutons des fiches) et `frontend/src/companies/companyImport.test.tsx` (test « suppression en cours : double clic ignoré, fiches bloquées même après réouverture du même import »).
- Référence : `docs/PROGRESS.md`, paragraphe « Contre-revue du code T-64 par Codex… ».

## 2. Ce qu'il faut vérifier

1. Rejoue N1 et N2 avec tes scénarios : corrigé / partiel / non corrigé.
2. Effets de bord : suppression en échec (le bouton se réactive, l'erreur s'affiche seulement dans la bonne vue) ; changement vers un autre import pendant la suppression ; relecture de l'import après suppression qui arrive en retard.
3. Signale aussi tout défaut évident que tu remarques dans ces fichiers, même hors de cette liste. Indique pour chaque constat s'il est rare et sans impact (report possible dans `docs/ERREURS_A_CORRIGER.md`).

## 3. Contrôles

Tu peux relancer `npx vitest run src/companies/companyImport.test.tsx` dans `frontend`. **Ne lance aucun test backend** (le backend n'a pas changé). Résultats de Claude (26/11), à citer comme communiqués et non rejoués : frontend lint, 121 tests et build réussis ; nouveau test vérifié rouge sans le correctif. Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 4. Format du rapport

Première ligne : `# Vérification T-64 — Constats N1 et N2`. Puis : en-tête (date, branche), tableau N1/N2, effets de bord, nouveaux constats éventuels (gravité, fichier et ligne, scénario, correctif minimal, rare ou non), **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).
