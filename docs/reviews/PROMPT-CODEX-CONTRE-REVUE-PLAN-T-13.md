# Prompt de contre-revue Codex — plan de T-13 (JD-18) : import des apprenants

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as rendu `docs/reviews/REVIEW-PLAN-T-13.md` (« Plan à revoir », 6 constats majeurs, 1 mineur, 1 suggestion, 3 questions). Claude a écrit la **version 2** du plan : tu fais une **contre-revue limitée**. **Tu ne modifies aucun fichier, tu ne commites rien, tu ne pousses rien.** Seul livrable : `docs/reviews/REVIEW-PLAN-T-13-contre-revue.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à revoir

- `docs/tasks/PLAN-T-13.md`, **version 2** : changements marqués **[v2]**. Branche `feature/T-13-import`, base `develop` (`81f9d02`), non commitée. Aucun code de T-13 n'est écrit.
- **Périmètre** : les corrections [v2], la nouvelle règle sur la classe et leurs effets de bord. Le reste du plan a déjà été revu.
- Le fichier réel d'apprenants n'est pas dans le dépôt : ne le cherche pas.

## 2. Décisions du responsable technique (20/11), à ne pas remettre en cause

- **Décision 2 [v2]** : la **classe est choisie avant l'import** (active) et **imposée à toutes les lignes** ; la colonne `Classe` sert de contrôle (comparaison sans casse ni espaces), une ligne d'une autre classe est **rejetée**. Elle remplace la correspondance des classes et les alias de la version 1 ; plus d'ajout de classe depuis l'import. Écart volontaire avec ADM-04 (« la classe vient du fichier »).
- **Décision 12** : 1 000 lignes, 5 Mo, 50 Mo décompressés, 50 000 chaînes partagées, traitement de 10 minutes au plus.
- **Décision 13** : seule la permission `learners.import` protège les routes de l'import.
- Les décisions 1 et 3 à 11 sont inchangées.

## 3. Ce qu'il faut vérifier

**A. Rejouer tes constats** : corrigé / partiel / non corrigé dans le plan, avec la section.

| # | Correction annoncée | Section |
|---|---|---|
| 1 | Création séparée en préparation et écriture ; identifiants préparés (mot de passe et hachage) ; état initial ; e-mail seulement pour un compte actif ; création manuelle T-12 inchangée | §4 |
| 2 | File dédiée, `retry_after` 900 s, délai 600 s, `tries = 1`, prise en charge conditionnelle, clé de confirmation idempotente, mise en file après commit, finalisation dans la transaction, message de file = identifiant seul, erreurs expurgées, imports abandonnés nettoyés | §3, §5 |
| 3 | Empreinte sur la décision de chaque ligne (numéro, décision, motif, cibles) | §2 |
| 4 | Ordre classes → comptes → fiches par identifiant ; lecture fraîche hors transaction ; déplacement de classe → annulation et reprise complète ; reprise bornée de l'opération entière ; tests MySQL à deux connexions | §5, Tests |
| 5 | Validation par ligne avec les limites de T-12 ; types de cellules XLSX ; formules refusées | §1, §2 |
| 6 | Dossier temporaire privé unique pour OpenSpout, supprimé en `finally`, nettoyage planifié ; fichier jamais sauvegardé ; `payload` et `passwords` masqués ; tests de confidentialité étendus | §1, §6, Tests |
| 7 | Sans objet avec la décision 2 (plus de correspondance de noms) : le confirmer | Décisions |
| 8 | Version 5.15.3, extensions, licences | §10 |

**B. Effets de bord** :
- la décision 2 : classe choisie active, apprenant connu dans une classe désormais inactive, contrôle de la colonne `Classe` (comparaison, colonne vide), cohérence avec `ClassUsage` et le changement de filière ;
- `tries = 1` et `failed()` : comportement de Laravel quand une tâche est relivrée après `retry_after` ; nettoyage à 20 minutes face à un traitement légitime de 10 minutes ;
- le risque résiduel accepté pour les fichiers temporaires (arrêt brutal, au plus une heure) : te paraît-il correctement borné et documenté ?
- le hachage d'avance de 1 000 mots de passe (durée, mémoire) dans la limite de 600 s.

**C. Signale aussi tout autre défaut évident** que tu remarques dans la version 2, même hors de cette liste.

## 4. Format du rapport `docs/reviews/REVIEW-PLAN-T-13-contre-revue.md`

En-tête ; périmètre ; tableau des constats rejoués ; effets de bord ; nouveaux constats éventuels (Bloquant / Majeur / Mineur / Suggestion, section, problème, correction proposée) ; questions au responsable technique ; **verdict** (« Plan validé », « Plan validé après corrections mineures », « Plan à revoir »).
